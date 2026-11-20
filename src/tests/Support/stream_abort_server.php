<?php

/**
 * Router script for `php -S`, used by StreamAbortTest. It boots the real app in the testing environment, signs the
 * given user in, fakes the model with one very long answer, and runs the real chat route through the real kernel,
 * so a client that hangs up is a real hang-up on a real socket.
 */

use App\Ai\Agents\RouterAgent;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Laravel\Ai\Embeddings;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Langfuse::fake();
Embeddings::fake();
RouterAgent::fake([str_repeat('word ', 20_000)]);
$app['auth']->guard('web')->setUser(User::findOrFail((int) $_GET['u']));

$request = Request::create('/notebooks/'.(int) $_GET['n'].'/chat', 'POST', ['question' => 'tell me everything']);
// php -S is not the console, so the CSRF check is live: present the request as a same-origin browser would.
$request->headers->set('Sec-Fetch-Site', 'same-origin');
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
