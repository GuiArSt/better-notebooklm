import { describe, expect, it } from 'vitest';
import { modelBadges, modelLabel } from '@/lib/model-badge';
import type { StudioItem } from '@/types/studio';

const item = (settings: unknown) => ({ settings }) as unknown as StudioItem;

describe('modelLabel', () => {
    it('names the models in plain words', () => {
        expect(
            modelLabel({ provider: 'anthropic', model: 'claude-sonnet-5-5' }),
        ).toBe('Claude Sonnet');
        expect(
            modelLabel({ provider: 'anthropic', model: 'claude-haiku-5-5' }),
        ).toBe('Claude Haiku');
        expect(
            modelLabel({ provider: 'mistral', model: 'mistral-small-latest' }),
        ).toBe('Mistral Small');
        expect(
            modelLabel({ provider: 'mistral', model: 'voxtral-mini-tts-3-0' }),
        ).toBe('Mistral Voxtral');
        expect(
            modelLabel({ provider: 'openai', model: 'gpt-image-2.5-flare' }),
        ).toBe('GPT Image');
        expect(modelLabel({ provider: 'elevenlabs', model: 'eleven_v3' })).toBe(
            'ElevenLabs',
        );
        expect(modelLabel({ provider: 'acme', model: 'x-1' })).toBe('Acme');
    });
});

describe('modelBadges', () => {
    it('says which model and plan wrote it', () => {
        expect(
            modelBadges(
                item({
                    tier: 'pro',
                    models: {
                        text: {
                            provider: 'anthropic',
                            model: 'claude-sonnet-5-5',
                        },
                    },
                }),
            ),
        ).toEqual(['Claude Sonnet · Pro']);
        expect(
            modelBadges(
                item({
                    tier: 'free',
                    models: {
                        text: {
                            provider: 'mistral',
                            model: 'mistral-small-latest',
                        },
                    },
                }),
            ),
        ).toEqual(['Mistral Small · Free']);
    });

    it('adds the voice and the picture', () => {
        expect(
            modelBadges(
                item({
                    tier: 'pro',
                    models: {
                        text: {
                            provider: 'anthropic',
                            model: 'claude-sonnet-5-5',
                        },
                        speech: { provider: 'elevenlabs', model: 'eleven_v3' },
                        image: {
                            provider: 'openai',
                            model: 'gpt-image-2.5-flare',
                        },
                    },
                }),
            ),
        ).toEqual([
            'Claude Sonnet · Pro',
            'ElevenLabs · Voice',
            'GPT Image · Image',
        ]);
    });

    it('is empty for an item with no recorded settings', () => {
        expect(modelBadges(item(null))).toEqual([]);
        expect(modelBadges(item({ tier: 'pro' }))).toEqual([]);
    });
});
