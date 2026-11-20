/** Whether a visit is to one notebook's page (/notebooks/12): the one that is slow to open and gets a skeleton at once. */
export const isNotebookVisit = (pathname: string): boolean =>
    /^\/notebooks\/\d+\/?$/.test(pathname);
