import { Download, Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { scriptParts } from '@/lib/script-tags';
import { clock } from '@/lib/studio-kinds';
import type { StudioItem } from '@/types/studio';

const SPEEDS = [0.75, 1, 1.25, 1.5, 2];

/** Two hosts, one MP3: play and pause, seek, speed, download, and the script underneath. */
export function AudioPlayer({ item }: { item: StudioItem }) {
    const audio = useRef<HTMLAudioElement>(null);
    const [playing, setPlaying] = useState(false);
    const [time, setTime] = useState(0);
    const [duration, setDuration] = useState(0);
    const [speed, setSpeed] = useState(1);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (audio.current) {
            audio.current.playbackRate = speed;
        }
    }, [speed]);

    const toggle = () => {
        const el = audio.current;

        if (!el) {
            return;
        }

        if (el.paused) {
            el.play().catch(() => setFailed(true));
        } else {
            el.pause();
        }
    };

    return (
        <div className="space-y-4" data-testid="audio-player">
            <audio
                ref={audio}
                src={item.audio_url ?? undefined}
                preload="metadata"
                onPlay={() => {
                    setPlaying(true);
                    setFailed(false);
                }}
                onError={() => setFailed(true)}
                onPause={() => setPlaying(false)}
                onEnded={() => setPlaying(false)}
                onTimeUpdate={(e) => setTime(e.currentTarget.currentTime)}
                onLoadedMetadata={(e) => setDuration(e.currentTarget.duration)}
            />
            <div className="flex items-center gap-3 rounded-xl border p-3">
                <Button
                    size="icon"
                    className="size-10 shrink-0 rounded-full"
                    onClick={toggle}
                    aria-label={playing ? 'Pause' : 'Play'}
                    data-testid="audio-toggle"
                >
                    {playing ? <Pause /> : <Play />}
                </Button>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <input
                        type="range"
                        min={0}
                        max={duration || 0}
                        step={0.1}
                        value={time}
                        aria-label="Seek"
                        onChange={(e) => {
                            if (audio.current) {
                                audio.current.currentTime = Number(
                                    e.target.value,
                                );
                            }
                        }}
                        className="w-full accent-primary"
                    />
                    <div className="flex justify-between text-xs text-muted-foreground tabular-nums">
                        <span>{clock(time)}</span>
                        <span>{clock(duration)}</span>
                    </div>
                </div>
                <select
                    aria-label="Playback speed"
                    value={speed}
                    onChange={(e) => setSpeed(Number(e.target.value))}
                    className="h-9 rounded-md border bg-background px-2 text-sm"
                    data-testid="audio-speed"
                >
                    {SPEEDS.map((s) => (
                        <option key={s} value={s}>
                            {s}×
                        </option>
                    ))}
                </select>
                {item.audio_url && (
                    <Button
                        asChild
                        variant="outline"
                        size="icon"
                        aria-label="Download"
                    >
                        <a
                            href={item.audio_url}
                            download={`${item.title}.mp3`}
                            data-testid="audio-download"
                        >
                            <Download />
                        </a>
                    </Button>
                )}
            </div>
            {failed && (
                <p
                    className="text-sm text-destructive"
                    role="alert"
                    data-testid="audio-failed"
                >
                    The audio could not be played. Reload the page, or download
                    it.
                </p>
            )}
            <p className="text-xs text-muted-foreground" data-testid="ai-label">
                AI-generated audio · synthetic voices
            </p>
            {item.script && item.script.length > 0 && (
                <div>
                    <h4 className="mb-2 text-sm font-semibold">Script</h4>
                    <ul className="space-y-2 text-sm">
                        {item.script.map((turn, i) => (
                            <li key={i} className="flex gap-2">
                                <span
                                    className={`mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${turn.speaker === 'A' ? 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200' : 'bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-200'}`}
                                >
                                    {turn.speaker}
                                </span>
                                <span>
                                    {scriptParts(turn.text).map((part, j) =>
                                        part.tag ? (
                                            <span
                                                key={j}
                                                className="mx-0.5 rounded-full bg-card-soft px-1.5 py-0.5 text-[10px] text-muted-foreground"
                                                data-testid="script-tag"
                                            >
                                                {part.text}
                                            </span>
                                        ) : (
                                            <span key={j}>{part.text} </span>
                                        ),
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
