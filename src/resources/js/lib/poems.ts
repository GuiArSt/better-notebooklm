export type Poem = { title: string | null; lines: string[] };

/** Reads the poems file: blocks split by a line of three dashes; "# Title" optional; HTML comments are ignored. */
export function parsePoems(source: string): Poem[] {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .split(/^---\s*$/m)
        .map((block) => block.trim())
        .filter((block) => block !== '')
        .map((block) => {
            const lines = block.split('\n').map((l) => l.trimEnd());
            const title = lines[0].startsWith('# ')
                ? lines[0].slice(2).trim()
                : null;

            return { title, lines: title ? lines.slice(1) : lines };
        });
}
