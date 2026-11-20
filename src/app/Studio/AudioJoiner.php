<?php

namespace App\Studio;

use App\Ai\SafeLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Joins the MP3 of each turn into one file. ffmpeg (in the media image) does it cleanly; where it is missing or fails,
 * the MP3 frames are simply appended, which every player handles.
 */
class AudioJoiner
{
    /** Written into the file's comment tag: the audio says it is synthetic (Themis P-4). */
    public const LABEL = 'AI-generated audio (synthetic voices)';

    /** @param  list<string>  $parts  raw MP3 bytes, in order */
    public function join(array $parts): string
    {
        // Through ffmpeg even for one part, so the tag is written properly; without ffmpeg, an ID3 tag of our own goes in front.
        return $this->withFfmpeg($parts) ?? $this->id3(self::LABEL).implode('', $parts);
    }

    /** A minimal ID3v2.3 tag holding one COMM (comment) frame: ffprobe and players read it as the comment. */
    private function id3(string $comment): string
    {
        $data = "\x00eng\x00".$comment; // ISO-8859-1, language, empty description, text
        $frame = 'COMM'.pack('N', strlen($data))."\x00\x00".$data;
        $size = strlen($frame);
        $syncsafe = chr(($size >> 21) & 0x7F).chr(($size >> 14) & 0x7F).chr(($size >> 7) & 0x7F).chr($size & 0x7F);

        return "ID3\x03\x00\x00".$syncsafe.$frame;
    }

    /** @param  list<string>  $parts */
    private function withFfmpeg(array $parts): ?string
    {
        $dir = sys_get_temp_dir().'/audio-'.Str::random(12);
        File::ensureDirectoryExists($dir);

        try {
            $list = '';
            foreach ($parts as $i => $bytes) {
                File::put("$dir/$i.mp3", $bytes);
                $list .= "file '$dir/$i.mp3'\n";
            }
            File::put("$dir/list.txt", $list);

            $result = Process::timeout(120)->run(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', "$dir/list.txt", '-c', 'copy', '-metadata', 'comment='.self::LABEL, "$dir/out.mp3"]);

            if ($result->successful() && File::exists("$dir/out.mp3") && File::size("$dir/out.mp3") > 0) {
                return File::get("$dir/out.mp3");
            }
            SafeLog::warning('ffmpeg did not join the audio (the frames are appended instead)', ['exit_code' => $result->exitCode()]);

            return null;
        } catch (\Throwable $e) {
            SafeLog::failure('Audio join (ffmpeg)', $e);

            return null;
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
