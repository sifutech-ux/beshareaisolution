import { describe, expect, it } from "vitest";
import { extractKeywords, splitSentences, summarize } from "./summarize";

describe("splitSentences", () => {
  it("splits on sentence boundaries", () => {
    expect(splitSentences("Hello world. How are you? Fine!")).toEqual([
      "Hello world.",
      "How are you?",
      "Fine!",
    ]);
  });

  it("collapses whitespace and ignores empty input", () => {
    expect(splitSentences("   \n  ")).toEqual([]);
  });
});

describe("extractKeywords", () => {
  it("ignores stop words and short tokens", () => {
    const keywords = extractKeywords(
      "The AI model learns from the model data. Data is key.",
    );
    expect(keywords).toContain("model");
    expect(keywords).toContain("data");
    expect(keywords).not.toContain("the");
    expect(keywords).not.toContain("is");
  });

  it("orders by frequency", () => {
    const keywords = extractKeywords("alpha alpha beta gamma", 2);
    expect(keywords[0]).toBe("alpha");
  });
});

describe("summarize", () => {
  it("returns the requested number of sentences", () => {
    const text =
      "Cats are great pets. Cats love to sleep all day long. " +
      "The weather today is sunny and warm.";
    const result = summarize(text, 1);
    expect(result.summary.length).toBeGreaterThan(0);
    expect(result.sentenceCount).toBe(3);
  });

  it("counts words correctly", () => {
    const result = summarize("one two three four five.");
    expect(result.wordCount).toBe(5);
  });

  it("falls back to the original text when there are no sentences", () => {
    const result = summarize("noboundaryhere");
    expect(result.summary).toBe("noboundaryhere");
  });
});
