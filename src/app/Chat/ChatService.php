<?php

namespace App\Chat;

use App\Ai\Exceptions\CapExceeded;
use App\Ai\Exceptions\UnsafeSourceText;
use App\Ai\Language;
use App\Ai\Router;
use App\Ai\SafeLog;
use App\Models\Notebook;
use App\Models\User;
use Generator;

/**
 * Answers a question from the checked sources of a notebook and yields events for the browser:
 *   {type: text, delta}  while the answer streams,
 *   {type: citations, citations: [...]}  once, with every cited passage resolved to its source and offsets,
 *   {type: error, message}  when a cap stops the call,
 *   {type: done}.
 */
class ChatService
{
    public function __construct(private Router $router, private Retriever $retriever, private CitationValidator $validator) {}

    /** @return Generator<int, array<string, mixed>> */
    public function answer(User $user, Notebook $notebook, string $question): Generator
    {
        try {
            $passages = $this->retriever->context($user, $notebook, $question);

            if ($passages->isEmpty()) {
                yield ['type' => 'text', 'delta' => 'There is nothing to answer from yet: add a source and keep it checked.'];
                yield ['type' => 'citations', 'citations' => []];
                yield ['type' => 'done'];

                return;
            }

            $input = SourceBlock::passages($passages)."\n\n".SourceBlock::userInput('question', $question).SourceBlock::closing('Answer the question in the user_input block from the passages above.');

            $stream = $this->router->stream($user, 'chat.answer', ['language' => Language::forPrompt(null, $question, 'the language of the question')], $input, 'chat', $notebook->id);
            foreach ($stream as $delta) {
                // A string is a piece of the answer; an array is an event of the Router's own (the length-limit error).
                yield is_array($delta) ? $delta : ['type' => 'text', 'delta' => $delta];
            }
            $result = $stream->getReturn();

            $resolved = $this->validator->resolve($result->text, $notebook, $result->traceId, array_values(array_map(intval(...), $passages->pluck('id')->all())));
            yield ['type' => 'citations', 'citations' => $resolved['citations']];
        } catch (CapExceeded $e) {
            yield ['type' => 'error', 'message' => $e->getMessage()];
        } catch (UnsafeSourceText $e) {
            // D-046: text that cannot be escaped is never sent. Logged as the error class and the notebook only, and the reader is told plainly.
            SafeLog::failure('Chat source block', $e, ['notebook_id' => $notebook->id]);
            yield ['type' => 'error', 'message' => $e->getMessage()];
        }

        yield ['type' => 'done'];
    }
}
