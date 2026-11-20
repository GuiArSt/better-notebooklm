/** A tiny drawn swatch for each infographic style: our own colours and shapes, one line of CSS each, never a copy of anything. */
export const STYLE_SWATCHES: Record<string, string> = {
    auto: 'conic-gradient(from 200deg, #38bdf8, #a78bfa, #f472b6, #fbbf24, #34d399, #38bdf8)',
    editorial:
        'linear-gradient(90deg, #2563eb 0 14%, transparent 14%), repeating-linear-gradient(0deg, #e5e7eb 0 2px, #ffffff 2px 9px)',
    sketch: 'repeating-linear-gradient(135deg, #fefce8 0 6px, #fde68a 6px 8px), #fefce8',
    clay: 'radial-gradient(circle at 30% 40%, #fda4af 0 28%, transparent 29%), radial-gradient(circle at 70% 60%, #93c5fd 0 26%, transparent 27%), #fde8d8',
    icons: 'linear-gradient(90deg, #f43f5e 0 25%, #f59e0b 25% 50%, #10b981 50% 75%, #3b82f6 75%)',
    blueprint:
        'repeating-linear-gradient(0deg, rgba(255,255,255,.35) 0 1px, transparent 1px 8px), repeating-linear-gradient(90deg, rgba(255,255,255,.35) 0 1px, transparent 1px 8px), #1e3a8a',
    paper: 'linear-gradient(180deg, #f5e6c8, #e9d3a8)',
};

/** The swatch's CSS background for a style, or a neutral one for a style this app does not know. */
export const swatchFor = (style: string): string =>
    STYLE_SWATCHES[style] ?? 'linear-gradient(180deg, #e5e7eb, #d1d5db)';
