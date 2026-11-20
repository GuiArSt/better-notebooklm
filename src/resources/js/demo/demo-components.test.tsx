import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { ChecklistBody } from '@/demo/guided-checklist';
import { WelcomeBody, WelcomeCard } from '@/demo/welcome-card';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { user: { role: 'member', name: 'Ann Lee' } } },
    }),
}));

const noop = () => undefined;
const checklist = (over: Partial<Parameters<typeof ChecklistBody>[0]>) =>
    renderToStaticMarkup(
        <ChecklistBody
            lang="de"
            ticked={[]}
            hidden={false}
            onTick={noop}
            onUntick={noop}
            onHidden={noop}
            onToggleLang={noop}
            {...over}
        />,
    );

describe('the welcome card', () => {
    it('speaks German first, with the first name and the cap', () => {
        const html = renderToStaticMarkup(
            <WelcomeBody
                name="Ann Lee"
                capUsd={25}
                lang="de"
                onToggle={noop}
            />,
        );

        expect(html).toContain('data-testid="demo-welcome"');
        expect(html).toContain('Willkommen, Ann.');
        expect(html).toContain('English');
    });

    it('speaks English after the toggle', () => {
        const html = renderToStaticMarkup(
            <WelcomeBody
                name="Ann Lee"
                capUsd={25}
                lang="en"
                onToggle={noop}
            />,
        );

        expect(html).toContain('Welcome, Ann.');
        expect(html).toContain('$25.00');
        expect(html).toContain('Deutsch');
    });

    it('renders nothing for a member', () => {
        expect(renderToStaticMarkup(<WelcomeCard capUsd={5} />)).toBe('');
    });
});

describe('the checklist', () => {
    it('shows five steps and the progress', () => {
        const html = checklist({ ticked: ['cite'] });

        expect(html.match(/data-testid="demo-step"/g)).toHaveLength(5);
        expect(html).toContain('1/5');
        expect(html).toContain('Hier anfangen');
    });

    it('shows only a small pill when hidden', () => {
        const html = checklist({ hidden: true });

        expect(html).toContain('Rundgang');
        expect(html).not.toContain('demo-step');
    });

    it('shows the done card when all five are ticked', () => {
        const html = checklist({
            ticked: ['ask', 'cite', 'source', 'podcast', 'pricing'],
        });

        expect(html).toContain('Geschafft');
        expect(html).not.toContain('demo-step');
    });
});
