export interface SummaryResult {
  summary: string;
  sentenceCount: number;
  wordCount: number;
  keywords: string[];
}

const STOP_WORDS = new Set([
  "the", "a", "an", "and", "or", "but", "of", "to", "in", "on", "for",
  "with", "as", "is", "are", "was", "were", "be", "been", "it", "this",
  "that", "these", "those", "at", "by", "from", "we", "you", "they",
  "i", "he", "she", "them", "our", "your", "their", "its", "if", "so",
]);

export function splitSentences(text: string): string[] {
  return text
    .replace(/\s+/g, " ")
    .trim()
    .split(/(?<=[.!?])\s+/)
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

export function extractKeywords(text: string, limit = 5): string[] {
  const counts = new Map<string, number>();
  const words = text.toLowerCase().match(/[a-z][a-z'-]*/g) ?? [];
  for (const word of words) {
    if (word.length < 3 || STOP_WORDS.has(word)) continue;
    counts.set(word, (counts.get(word) ?? 0) + 1);
  }
  return [...counts.entries()]
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .slice(0, limit)
    .map(([word]) => word);
}

export function summarize(text: string, maxSentences = 2): SummaryResult {
  const sentences = splitSentences(text);
  const words = text.match(/\S+/g) ?? [];
  const keywords = extractKeywords(text);

  const scored = sentences.map((sentence, index) => {
    const sentenceWords = sentence.toLowerCase().match(/[a-z][a-z'-]*/g) ?? [];
    const score = sentenceWords.reduce(
      (acc, word) => acc + (keywords.includes(word) ? 1 : 0),
      0,
    );
    return { sentence, index, score };
  });

  const summary = scored
    .slice()
    .sort((a, b) => b.score - a.score || a.index - b.index)
    .slice(0, Math.max(1, maxSentences))
    .sort((a, b) => a.index - b.index)
    .map((s) => s.sentence)
    .join(" ");

  return {
    summary: summary || text.trim(),
    sentenceCount: sentences.length,
    wordCount: words.length,
    keywords,
  };
}
