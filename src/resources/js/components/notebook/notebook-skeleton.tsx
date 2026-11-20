import { Skeleton } from '@/components/ui/skeleton';

/** The notebook's three panels as quiet placeholders, shown the moment a notebook is opened, until its data arrives. */
export function NotebookSkeleton() {
    return (
        <div
            className="bg-constellation-dense flex h-full flex-col gap-3 p-2 sm:p-3"
            role="status"
            aria-label="Opening the notebook"
            data-testid="notebook-skeleton"
        >
            <Skeleton className="h-9 w-64 max-w-full rounded-full" />
            <div className="grid min-h-0 flex-1 gap-3 lg:grid-cols-[1fr_2fr_1fr]">
                {['sources', 'chat', 'studio'].map((panel) => (
                    <div
                        key={panel}
                        className="space-y-3 rounded-panel bg-panel p-4 max-lg:first:hidden max-lg:last:hidden"
                    >
                        <Skeleton className="h-5 w-24" />
                        <Skeleton className="h-12 w-full rounded-2xl" />
                        <Skeleton className="h-12 w-full rounded-2xl" />
                        <Skeleton className="h-12 w-3/4 rounded-2xl" />
                    </div>
                ))}
            </div>
        </div>
    );
}
