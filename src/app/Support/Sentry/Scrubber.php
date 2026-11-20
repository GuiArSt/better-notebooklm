<?php

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * What leaves for Sentry (D-043): the error class, the file and line, the route's path and the user's id. Same rule as SafeLog.
 * A message can carry what a user typed, and a request carries a body, cookies and headers: all of it is dropped here.
 */
class Scrubber
{
    public static function event(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        $event->setRequest(array_filter([
            'method' => $request['method'] ?? null,
            'url' => isset($request['url']) ? strtok((string) $request['url'], '?') : null,
        ]));

        $id = $event->getUser()?->getId();
        $event->setUser($id === null ? null : UserDataBag::createFromUserIdentifier((string) $id));

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue('');

            // A frame's variables are the arguments of the call: what a user typed can sit in them.
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }

        $event->setMessage('');
        $event->setBreadcrumb([]);
        $event->setExtra([]);
        $event->setContext('runtime', []);

        return $event;
    }
}
