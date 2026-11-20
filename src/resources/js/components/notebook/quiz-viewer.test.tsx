import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { QuizViewer, answerIndex } from '@/components/notebook/quiz-viewer';
import type { StudioItem } from '@/types/studio';

const quiz = [
    {
        question: 'Which database holds the vectors?',
        options: ['SQLite', 'Postgres', 'Redis', 'Mongo'],
        answer: 1,
        hint: 'It starts with P.',
        explanation: 'The notebook stores passages in Postgres with pgvector.',
        refs: [7],
    },
    {
        question: 'Who checks the citations?',
        options: ['The model', 'The browser', 'The server', 'Nobody'],
        answer: 'C',
        hint: '',
        explanation: 'The server drops markers it did not show.',
        refs: [8],
    },
];
const item = {
    id: 1,
    notebook_id: 1,
    kind: 'quiz',
    title: 'Quiz',
    status: 'done',
    step: null,
    step_n: null,
    step_total: null,
    tree: null,
    content: null,
    citations: [],
    script: null,
    audio_url: null,
    error: null,
    created_at: new Date().toISOString(),
    quiz,
} as StudioItem;

describe('The quiz viewer', () => {
    it('reads the right option as an index from a number or a letter', () => {
        expect(answerIndex(quiz[0])).toBe(1);
        expect(answerIndex(quiz[1])).toBe(2);
    });

    it('opens on question 1 of N with four lettered options, a hint toggle and Next waiting for a pick', () => {
        const html = renderToStaticMarkup(
            <QuizViewer item={item} onOpenCitation={() => undefined} />,
        );

        expect(html).toContain('1 / 2');
        expect(html).toContain('Which database holds the vectors?');
        expect((html.match(/data-testid="quiz-option"/g) ?? []).length).toBe(4);
        for (const letter of ['A', 'B', 'C', 'D']) {
            expect(html).toContain(`>${letter}</span>`);
        }
        expect(html).toContain('Hint');
        expect(html).toMatch(
            /data-testid="quiz-next"[^>]*disabled|disabled=""[^>]*data-testid="quiz-next"/,
        );
        // the answer is not given away before a pick
        expect(html).not.toContain('The notebook stores passages');
        expect(html).not.toContain('data-state="right"');
    });

    it('says so when a quiz has no questions', () => {
        expect(
            renderToStaticMarkup(
                <QuizViewer
                    item={{ ...item, quiz: [] }}
                    onOpenCitation={() => undefined}
                />,
            ),
        ).toContain('no questions');
    });
});
