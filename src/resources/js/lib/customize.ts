import type { StudioKind } from '@/types/studio';

/** What the Customize step offers for each kind of item (D-035), and the request it builds. */
export type Choice = {
    id: string;
    label: string;
    options: { value: string; label: string; hint?: string }[];
    default: string;
    /** Drawn as a grid of equal tiles with a swatch each, for rows too long to sit as pills (the infographic's styles). */
    tiles?: boolean;
};

export type KindForm = {
    title: string;
    focusLabel: string;
    focusExamples: string[];
    choices: Choice[];
};

export const LANGUAGES = [
    { value: 'auto', label: 'The language of the sources' },
    { value: 'en', label: 'English' },
    { value: 'fr', label: 'Français' },
    { value: 'de', label: 'Deutsch' },
    { value: 'es', label: 'Español' },
    { value: 'it', label: 'Italiano' },
    { value: 'pt', label: 'Português' },
    { value: 'nl', label: 'Nederlands' },
];

const seg = (
    id: string,
    label: string,
    options: [string, string, string?][],
    dflt: string,
): Choice => ({
    id,
    label,
    options: options.map(([value, text, hint]) => ({
        value,
        label: text,
        hint,
    })),
    default: dflt,
});

/** The podcast choices the server offers this user (config/podcast.php): the formats, and the personas only for whoever hears ElevenLabs. */
export type Podcast = {
    formats: { value: string; label: string }[];
    default_format: string;
    /** The length a Brief starts on unless the user chose one. */
    brief_length: string;
    personas: { value: string; name: string; role: string; line: string }[];
};

/** What the server offers for an infographic (config/infographics.php); `available` is false unless the switch is on and this user may make one. */
export type Infographic = {
    available: boolean;
    orientations: { value: string; label: string }[];
    styles: { value: string; label: string }[];
    details: { value: string; label: string }[];
    default_orientation: string;
    default_style: string;
    default_detail: string;
};

/** The choices the server makes for this user, by kind. */
export type Offers = { podcast?: Podcast; infographic?: Infographic };

/** One line under the chosen format; the names come from the server, these explain them. */
const FORMAT_HINTS: Record<string, string> = {
    deep_dive: 'A lively conversation across the sources',
    brief: 'Core ideas in a couple of minutes',
    critique: 'An expert review of the material',
    debate: 'Two hosts take opposite sides',
};

export const FORMS: Record<StudioKind, KindForm> = {
    audio: {
        title: 'Audio overview',
        focusLabel: 'What should the hosts focus on?',
        focusExamples: [
            'Focus on the first source only',
            'Explain it to someone who has never heard of the topic',
            'Concentrate on the disagreements between the sources',
        ],
        choices: [
            seg(
                'length',
                'Length',
                [
                    ['short', 'Short'],
                    ['default', 'Default'],
                    ['long', 'Long'],
                ],
                'default',
            ),
        ],
    },
    briefing: {
        title: 'Briefing document',
        focusLabel: 'What should the report focus on?',
        focusExamples: [
            'Keep it to one page for a busy reader',
            'Concentrate on risks and open questions',
            'Write it for a new team member',
        ],
        choices: [],
    },
    study_guide: {
        title: 'Study guide',
        focusLabel: 'What should the guide focus on?',
        focusExamples: [
            'Cover only the first two sources',
            'Prepare me for an exam on definitions',
            'Emphasise the key dates and numbers',
        ],
        choices: [],
    },
    faq: {
        title: 'FAQ',
        focusLabel: 'What should the questions be about?',
        focusExamples: [
            'Questions a customer would ask',
            'Focus on how it works, not why',
            'Only what the second source says',
        ],
        choices: [],
    },
    mindmap: {
        title: 'Mind map',
        focusLabel: 'What should the topic be?',
        focusExamples: [
            'The mind map must be restricted to a specific source',
            'Focus solely on the key concepts',
            'Help me study the causes and effects',
        ],
        choices: [],
    },
    quiz: {
        title: 'Quiz',
        focusLabel: 'What should the quiz focus on?',
        focusExamples: [
            'Test definitions only',
            'Ask about the numbers and dates',
            'Questions a beginner could answer after reading',
        ],
        choices: [
            seg(
                'questions',
                'Number of questions',
                [
                    ['5', '5'],
                    ['6', '6'],
                    ['7', '7'],
                    ['8', '8'],
                ],
                '6',
            ),
            seg(
                'difficulty',
                'Difficulty',
                [
                    ['easy', 'Easy'],
                    ['medium', 'Medium'],
                    ['hard', 'Hard'],
                ],
                'medium',
            ),
        ],
    },
    slides: {
        title: 'Slide deck',
        focusLabel: 'What should the deck focus on?',
        focusExamples: [
            'A short pitch for a non-expert',
            'Concentrate on the main argument and its evidence',
            'A summary for a team meeting',
        ],
        choices: [
            seg(
                'slides',
                'Number of slides',
                [
                    ['6', '6'],
                    ['8', '8'],
                    ['10', '10'],
                    ['12', '12'],
                ],
                '8',
            ),
        ],
    },
    infographic: {
        title: 'Infographic',
        focusLabel: 'Describe the infographic you want',
        focusExamples: [
            'Focus on the timeline of events',
            'Compare the main options side by side',
            'Highlight the key numbers for a busy reader',
        ],
        choices: [],
    },
};

export type CustomizeRequest = {
    kind: StudioKind;
    language: string;
    sources: number[];
    focus: string;
    options: Record<string, string>;
};

/** The choices a kind offers: its own, and from the server for audio its podcast formats, for an infographic its orientation, style and detail. */
export function choicesFor(kind: StudioKind, offers: Offers = {}): Choice[] {
    const own = FORMS[kind].choices;
    const { podcast, infographic } = offers;

    if (kind === 'audio' && podcast) {
        return [
            {
                id: 'format',
                label: 'Format',
                options: podcast.formats.map((f) => ({
                    ...f,
                    hint: FORMAT_HINTS[f.value],
                })),
                default: podcast.default_format,
            },
            ...own,
        ];
    }

    if (kind === 'infographic' && infographic) {
        return [
            {
                id: 'orientation',
                label: 'Orientation',
                options: infographic.orientations,
                default: infographic.default_orientation,
            },
            {
                id: 'style',
                label: 'Style',
                options: infographic.styles,
                default: infographic.default_style,
                tiles: true,
            },
            {
                id: 'detail',
                label: 'Level of detail',
                options: infographic.details,
                default: infographic.default_detail,
            },
        ];
    }

    return own;
}

export function defaultOptions(
    kind: StudioKind,
    offers: Offers = {},
): Record<string, string> {
    return Object.fromEntries(
        choicesFor(kind, offers).map((c) => [c.id, c.default]),
    );
}

/**
 * One click on a choice. Picking the Brief format starts it on the Brief's own length unless the user has chosen a length;
 * leaving Brief puts the length back to its default. Returns the new options and which choices the user has touched.
 */
export function choose(
    kind: StudioKind,
    state: { options: Record<string, string>; touched: string[] },
    id: string,
    value: string,
    offers: Offers = {},
): { options: Record<string, string>; touched: string[] } {
    const options = { ...state.options, [id]: value };
    const touched = state.touched.includes(id)
        ? state.touched
        : [...state.touched, id];
    const podcast = offers.podcast;

    if (
        kind === 'audio' &&
        podcast &&
        id === 'format' &&
        !touched.includes('length')
    ) {
        options.length =
            value === 'brief'
                ? podcast.brief_length
                : defaultOptions(kind, offers).length;
    }

    return { options, touched };
}

/** The request the form sends: the kind, the language, the chosen sources, the focus text and the kind's own choices. */
export function buildRequest(
    kind: StudioKind,
    form: {
        language: string;
        sourceIds: number[];
        focus: string;
        options: Record<string, string>;
    },
    offers: Offers = {},
): CustomizeRequest {
    return {
        kind,
        language: form.language,
        sources: [...form.sourceIds].sort((a, b) => a - b),
        focus: form.focus.trim().slice(0, 500),
        options: { ...defaultOptions(kind, offers), ...form.options },
    };
}

/** The bar: how much of the budget is used already, and how much this item would add. Both as 0 to 100. */
export function usageBar(
    spent: number,
    cap: number,
    estimate: number | null,
): { used: number; added: number; over: boolean } {
    const total = Math.max(cap, 0.000001);
    const used = Math.min(100, (spent / total) * 100);
    const added =
        estimate === null ? 0 : Math.min(100 - used, (estimate / total) * 100);

    return { used, added, over: estimate !== null && spent + estimate > cap };
}

/** The query string of the estimate route: the same choices as the request, without the focus text (it does not change the price). */
export function estimateQuery(request: CustomizeRequest): string {
    const params = new URLSearchParams({
        kind: request.kind,
        language: request.language,
    });

    request.sources.forEach((id) => params.append('sources[]', String(id)));
    Object.entries(request.options).forEach(([k, v]) =>
        params.append(`options[${k}]`, v),
    );

    return params.toString();
}

export type Estimate = {
    estimate_usd: number;
    remaining_micro_usd: number;
    cap_micro_usd: number;
    within_budget: boolean;
};
