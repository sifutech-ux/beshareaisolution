// Set the current year in the footer.
const yearEl = document.getElementById("year");
if (yearEl) {
  yearEl.textContent = String(new Date().getFullYear());
}

// Gates without a real destination yet should not navigate anywhere.
document.querySelectorAll('a.gate[href="#"]').forEach((gate) => {
  gate.addEventListener("click", (event) => {
    event.preventDefault();
  });
});
