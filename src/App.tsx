import { useMemo, useState } from "react";
import { summarize } from "./lib/summarize";
import "./App.css";

const SAMPLE_TEXT =
  "BeShare AI Solution helps teams turn long documents into clear, actionable summaries. " +
  "It highlights the most important sentences and surfaces the key topics automatically. " +
  "This lets busy people understand large amounts of text in seconds. " +
  "The tool runs entirely in the browser, so no data ever leaves your machine.";

export default function App() {
  const [text, setText] = useState(SAMPLE_TEXT);
  const [maxSentences, setMaxSentences] = useState(2);

  const result = useMemo(
    () => summarize(text, maxSentences),
    [text, maxSentences],
  );

  return (
    <main className="app">
      <header className="app__header">
        <h1>BeShare AI Solution</h1>
        <p className="app__tagline">
          Instant, private text summarization &mdash; right in your browser.
        </p>
      </header>

      <section className="card">
        <label className="field">
          <span className="field__label">Your text</span>
          <textarea
            className="field__input"
            aria-label="Text to summarize"
            rows={8}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder="Paste an article, email, or report here..."
          />
        </label>

        <label className="field field--inline">
          <span className="field__label">Summary length</span>
          <input
            type="range"
            min={1}
            max={5}
            value={maxSentences}
            aria-label="Number of summary sentences"
            onChange={(e) => setMaxSentences(Number(e.target.value))}
          />
          <span className="field__value">{maxSentences} sentence(s)</span>
        </label>
      </section>

      <section className="card result" aria-live="polite">
        <h2>Summary</h2>
        <p className="result__summary" data-testid="summary">
          {result.summary}
        </p>

        <div className="stats">
          <div className="stat">
            <span className="stat__value" data-testid="word-count">
              {result.wordCount}
            </span>
            <span className="stat__label">words</span>
          </div>
          <div className="stat">
            <span className="stat__value" data-testid="sentence-count">
              {result.sentenceCount}
            </span>
            <span className="stat__label">sentences</span>
          </div>
        </div>

        {result.keywords.length > 0 && (
          <div className="keywords">
            <span className="keywords__label">Key topics:</span>
            <ul className="keywords__list">
              {result.keywords.map((keyword) => (
                <li key={keyword} className="keyword">
                  {keyword}
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>
    </main>
  );
}
