/**
 * The reviewer UI's words (D-059, docs/plans/demo-makeup.md): German first, each with its English twin.
 * Formal "Sie", first person "ich" (the owner speaking). Placeholders in {braces} are filled by fill().
 * Kept apart from the app's i18n files on purpose: these show German to a reviewer whatever the app's language is.
 */

export type Lang = 'de' | 'en';
export type Bilingual = Record<Lang, string>;

export const TOGGLE: Bilingual = { de: 'English', en: 'Deutsch' };

export const WELCOME = {
    badge: { de: 'Ihr Prüfzugang', en: 'Your reviewer account' },
    title: { de: 'Willkommen, {name}.', en: 'Welcome, {name}.' },
    paragraphs: [
        {
            de: 'Schön, dass Sie da sind. Better NotebookLM ist mein Nachbau von Google NotebookLM, gebaut in drei Tagen für meine Bewerbung bei Ihnen. Es ist eine Demo, kein Produkt. Aber was Sie anklicken können, funktioniert wirklich: Quellen, ein Chat, der nur aus Ihren Quellen antwortet und jede Aussage belegt, Notizen und das Studio mit Podcast, Quiz, Folien und Mindmap.',
            en: "Glad you're here. Better NotebookLM is my replica of Google NotebookLM, built in three days for my application to your company. It is a demo, not a product. But what you can click really works: sources, a chat that answers only from your sources and backs every claim, notes, and the Studio with a podcast, a quiz, slides and a mind map.",
        },
        {
            de: 'Sie haben alles, was sonst Pro ist: Claude im Chat, die ElevenLabs-Stimmen, vier Podcast-Persönlichkeiten und ein eigenes Budget von {cap}, das ich übernehme. Wenn etwas hakt, sagt die App es Ihnen offen, und ich sehe es auch.',
            en: 'You have everything that is otherwise pro: Claude in the chat, the ElevenLabs voices, four podcast personas and a budget of {cap} of your own, which I cover. If something breaks, the app tells you plainly, and I see it too.',
        },
        {
            de: 'Unten steht, was echt ist und was nicht. Mein Vorschlag für die ersten fünf Minuten wartet auf der Startseite.',
            en: 'Below is what is real and what is not. My suggestion for your first five minutes waits on the home page.',
        },
    ],
} as const;

export type StepId = 'ask' | 'cite' | 'source' | 'podcast' | 'pricing';

export const CHECKLIST = {
    title: {
        de: 'Ihr Rundgang · etwa 5 Minuten',
        en: 'Your tour · about 5 minutes',
    },
    start: { de: 'Hier anfangen', en: 'Start here' },
    hide: { de: 'Ausblenden', en: 'Hide' },
    show: { de: 'Rundgang', en: 'Tour' },
    tick: { de: 'Als erledigt markieren', en: 'Mark as done' },
    untick: { de: 'Als offen markieren', en: 'Mark as not done' },
    steps: [
        {
            id: 'ask',
            de: 'Öffnen Sie das vorbereitete Notizbuch und fragen Sie es etwas, gern auf Deutsch.',
            en: 'Open the ready notebook and ask it anything.',
        },
        {
            id: 'cite',
            de: 'Fahren Sie über eine Quellenangabe [1]: Sie sehen das genaue Zitat.',
            en: 'Hover a citation [1]: you see the exact quote.',
        },
        {
            id: 'source',
            de: 'Fügen Sie eine eigene Quelle hinzu, gern die Webseite Ihres Unternehmens, und fragen Sie danach.',
            en: "Add a source of your own, your company's web page for instance, and ask about it.",
        },
        {
            id: 'podcast',
            de: 'Hören Sie im Studio den fertigen Podcast an, oder erstellen Sie einen eigenen: Unter „Anpassen“ wählen Sie Format, Persönlichkeit und Sprache.',
            en: 'Listen to the ready podcast in the Studio, or make your own: Customize picks the format, a persona and the language.',
        },
        {
            id: 'pricing',
            de: 'Werfen Sie einen Blick auf die Preise und den Checkout im Stripe-Testmodus.',
            en: "Take a look at Pricing and the checkout in Stripe's test mode.",
        },
    ] as ReadonlyArray<{ id: StepId } & Bilingual>,
    doneTitle: {
        de: 'Geschafft. Danke für Ihre Zeit.',
        en: 'Done. Thank you for your time.',
    },
    doneBody: {
        de: 'Der Rest gehört Ihnen: Code und Video stehen in meiner E-Mail.',
        en: 'The rest is yours: the code and the video are in my email.',
    },
} as const;

export type WinkId =
    | 'impressum'
    | 'privacy'
    | 'featured'
    | 'firstAnswer'
    | 'citation'
    | 'greyed'
    | 'quiz'
    | 'podcast'
    | 'meter'
    | 'pricing'
    | 'lateNight';

/** The winks: each fires once, ever, at most one a minute (state.ts). Never on an error, never near a destructive button. */
export const WINKS: Record<WinkId, Bilingual> = {
    impressum: {
        de: 'Ah, Sie prüfen, ob ich meine Hausaufgaben gemacht habe? Impressum und Datenschutzerklärung liegen auf meiner eigenen Seite, weil ich diese Demo persönlich anbiete.',
        en: 'Ah, checking whether I did my homework? The legal notice and the privacy policy live on my own site, because I offer this demo personally.',
    },
    privacy: {
        de: 'Gründlich. Das gefällt mir. Kurz gesagt: nichts wird verkauft, alles wird am Ende der Demo gelöscht.',
        en: 'Thorough. I like that. In short: nothing is sold, and everything is deleted when the demo ends.',
    },
    featured: {
        de: 'Ich lege Ihnen gerade eine eigene Kopie an. Ihre Fragen bleiben Ihre. Gleich geht es los.',
        en: "I'm making you a copy of your own. Your questions stay yours. One moment.",
    },
    firstAnswer: {
        de: 'Jede Zahl in Klammern ist eine Quelle. Fahren Sie mal drüber.',
        en: 'Every number in brackets is a source. Try hovering one.',
    },
    citation: {
        de: 'Genau das ist der Trick: keine Behauptung ohne Beleg.',
        en: "That's the whole trick: no claim without a quote.",
    },
    greyed: {
        de: 'Ehrlich: Das hat in drei Tagen nicht gepasst. Gut gefunden, trotzdem.',
        en: "Honestly: that didn't fit in three days. Good find, though.",
    },
    quiz: {
        de: 'Keine Sorge, das wird nicht benotet. Ich schon.',
        en: "Don't worry, this isn't graded. I am.",
    },
    podcast: {
        de: 'Ihr Podcast. Kopfhörer empfohlen.',
        en: 'Your podcast. Headphones recommended.',
    },
    meter: {
        de: 'Das zahle ich, nicht Sie. Großzügig genug für einige Podcasts.',
        en: "I'm paying for this, not you. Enough for quite a few podcasts.",
    },
    pricing: {
        de: 'Der Checkout ist zum Anschauen da: Testkarte 4242 4242 4242 4242, kein Geld.',
        en: 'The checkout is here to look at: test card 4242 4242 4242 4242, no money.',
    },
    lateNight: {
        de: 'So spät noch am Testen? Danke, ehrlich.',
        en: 'Testing this late? Thank you, really.',
    },
};

export const SILENCE: Bilingual = {
    de: 'Keine Kommentare mehr',
    en: 'No more comments',
};

/** Replaces each {key} with its value; a key with no value stays visible, so a missing one is seen, not hidden. */
export function fill(text: string, vars: Record<string, string>): string {
    return text.replace(
        /\{(\w+)\}/g,
        (whole, key: string) => vars[key] ?? whole,
    );
}

/** Money in the reviewer's language: "25,00 $" in German, "$25.00" in English. */
export function money(usd: number, lang: Lang): string {
    return new Intl.NumberFormat(lang === 'de' ? 'de-DE' : 'en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(usd);
}
