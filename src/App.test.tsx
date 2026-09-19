import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import App from "./App";

describe("App", () => {
  it("renders the heading and an initial summary", () => {
    render(<App />);
    expect(
      screen.getByRole("heading", { name: /beshare ai solution/i }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("summary").textContent).not.toBe("");
  });

  it("updates stats when the user types new text", async () => {
    const user = userEvent.setup();
    render(<App />);
    const textarea = screen.getByLabelText(/text to summarize/i);
    await user.clear(textarea);
    await user.type(textarea, "one two three.");
    expect(screen.getByTestId("word-count").textContent).toBe("3");
  });
});
