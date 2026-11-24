// Set the current year in the footer.
const yearEl = document.getElementById("year");
if (yearEl) {
  yearEl.textContent = String(new Date().getFullYear());
}

// Close the mobile menu when a visitor follows an in-page product link.
const menu = document.querySelector(".nav__menu");
menu?.addEventListener("click", (event) => {
  if (event.target.closest("a")) menu.open = false;
});
