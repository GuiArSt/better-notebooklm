/** The chat of each notebook lives only in this browser (key prefix below). It is cleared on sign-out and after account deletion. */
export const CHAT_KEY_PREFIX = 'notebook-chat-';

export function clearChatHistory(): void {
    try {
        Object.keys(localStorage)
            .filter((key) => key.startsWith(CHAT_KEY_PREFIX))
            .forEach((key) => localStorage.removeItem(key));
    } catch {
        // Storage blocked: there is nothing stored to clear.
    }
}
