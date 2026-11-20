import type { StudioItem } from '@/types/studio';

type Model = { provider: string; model: string };

/** A model's short, plain name for people: "Claude Sonnet", "Mistral Small", "GPT Image", "ElevenLabs". */
export function modelLabel({ provider, model }: Model): string {
    const id = model.toLowerCase();

    if (provider === 'elevenlabs') {
        return 'ElevenLabs';
    }

    if (provider === 'anthropic' || id.startsWith('claude')) {
        const family = /claude-(sonnet|haiku|opus)/.exec(id)?.[1];

        return family
            ? `Claude ${family[0].toUpperCase()}${family.slice(1)}`
            : 'Claude';
    }

    if (
        provider === 'mistral' ||
        id.startsWith('mistral') ||
        id.startsWith('voxtral')
    ) {
        const size = /mistral-(small|medium|large|embed)/.exec(id)?.[1];

        return id.startsWith('voxtral')
            ? 'Mistral Voxtral'
            : size
              ? `Mistral ${size[0].toUpperCase()}${size.slice(1)}`
              : 'Mistral';
    }

    if (provider === 'openai') {
        return id.includes('image') ? 'GPT Image' : 'GPT';
    }

    return provider
        ? `${provider[0].toUpperCase()}${provider.slice(1)}`
        : model;
}

/**
 * The quiet labels for the models that made an item, from what the server recorded: the writing model with the plan
 * ("Claude Sonnet · Pro"), then the voice or the picture ("ElevenLabs · Voice", "GPT Image · Image"). Empty for an item
 * made before settings were recorded.
 */
export function modelBadges(item: Pick<StudioItem, 'settings'>): string[] {
    const s = item.settings;
    const models = s?.models;

    if (!s || !models) {
        return [];
    }

    const badges: string[] = [];

    if (models.text) {
        const plan =
            s.tier === 'pro' ? 'Pro' : s.tier === 'free' ? 'Free' : null;

        badges.push(
            [modelLabel(models.text), plan].filter(Boolean).join(' · '),
        );
    }

    if (models.speech) {
        badges.push(`${modelLabel(models.speech)} · Voice`);
    }

    if (models.image) {
        badges.push(`${modelLabel(models.image)} · Image`);
    }

    return badges;
}
