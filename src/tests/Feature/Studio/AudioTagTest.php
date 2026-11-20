<?php

namespace Tests\Feature\Studio;

use App\Studio\AudioJoiner;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** P-4 (Themis): the joined MP3 says it is synthetic. */
class AudioTagTest extends TestCase
{
    private const LABEL = 'AI-generated audio (synthetic voices)';

    private function ffmpeg(): bool
    {
        return trim((string) shell_exec('command -v ffmpeg')) !== '' && trim((string) shell_exec('command -v ffprobe')) !== '';
    }

    private function tone(int $hz): string
    {
        $file = sys_get_temp_dir()."/tone-$hz.mp3";
        shell_exec('ffmpeg -y -loglevel error -f lavfi -i sine=frequency='.$hz." -t 1 -q:a 9 $file");
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /** What ffprobe reads as the comment of these bytes. */
    private function comment(string $mp3): string
    {
        $file = sys_get_temp_dir().'/tagged-'.uniqid().'.mp3';
        file_put_contents($file, $mp3);
        $out = trim((string) shell_exec("ffprobe -v error -show_entries format_tags=comment -of default=nw=1:nk=1 $file"));
        @unlink($file);

        return $out;
    }

    public function test_the_joined_mp3_carries_the_label_in_its_comment_tag(): void
    {
        if (! $this->ffmpeg()) {
            $this->markTestSkipped('ffmpeg and ffprobe are not installed here');
        }

        $joined = app(AudioJoiner::class)->join([$this->tone(300), $this->tone(600)]);

        $this->assertSame(self::LABEL, $this->comment($joined));
    }

    public function test_a_single_part_is_tagged_too(): void
    {
        if (! $this->ffmpeg()) {
            $this->markTestSkipped('ffmpeg and ffprobe are not installed here');
        }

        $this->assertSame(self::LABEL, $this->comment(app(AudioJoiner::class)->join([$this->tone(440)])));
    }

    public function test_the_label_is_kept_even_when_ffmpeg_is_not_available(): void
    {
        if (! $this->ffmpeg()) {
            $this->markTestSkipped('ffprobe is needed to read the tag back');
        }
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        $joined = app(AudioJoiner::class)->join([$this->tone(300), $this->tone(600)]);
        $single = app(AudioJoiner::class)->join([$this->tone(440)]);

        $this->assertSame(self::LABEL, $this->comment($joined), 'the appended-frames fallback writes its own ID3 tag');
        $this->assertSame(self::LABEL, $this->comment($single));
    }

    public function test_the_label_is_in_the_bytes_without_ffprobe(): void
    {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        $joined = app(AudioJoiner::class)->join(['AAAA', 'BBBB']);

        $this->assertStringStartsWith('ID3', $joined);
        $this->assertStringContainsString(self::LABEL, $joined);
        $this->assertStringContainsString('AAAABBBB', $joined);
    }

    /** HC-13 (Hipparchus): an ffmpeg failure falls back to appended frames, but is logged. */
    public function test_an_ffmpeg_failure_is_logged_and_the_frames_are_still_joined_with_the_tag(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        Process::fake(['*' => Process::result('', 'ffmpeg: not found', 127)]);

        $joined = app(AudioJoiner::class)->join(['AAA', 'BBB']);

        $this->assertStringStartsWith('ID3', $joined);
        $this->assertStringEndsWith('AAABBB', $joined);
        $this->assertStringContainsString('ffmpeg did not join the audio', implode("\n", $logged));
        $this->assertStringContainsString('"exit_code":127', implode("\n", $logged));
        $this->assertStringNotContainsString('not found', implode("\n", $logged), 'the process output is not logged');
    }
}
