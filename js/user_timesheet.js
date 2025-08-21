document.getElementById("editForm").addEventListener("submit", function(e) {
  const rows = Array.from(document.querySelectorAll("tbody tr"));
  let valid = true;

  rows.forEach((row, index) => {
    const fields = ["TimeIN", "LunchStart", "LunchEnd", "TimeOut"];
    let changed = false;

    fields.forEach(field => {
      const input = row.querySelector(`[name="entries[${index}][${field}]"]`);
      if (input && input.value !== input.dataset.original) {
        changed = true;
      }
    });

    const reason = row.querySelector(`[name="entries[${index}][Reason]"]`);
    if (changed && reason.value.trim() === "") {
      reason.style.borderColor = "red";
      reason.placeholder = "Required for edited rows";
      valid = false;
    } else {
      reason.style.borderColor = "";
    }
  });

  if (!valid) {
    e.preventDefault();
    showPopup("⚠️ Please provide a reason for each time change.");
  }
});

function showPopup(message) {
  document.getElementById("popupMessage").textContent = message;
  document.getElementById("popupFeedback").classList.remove("hidden");
}

function closePopup() {
  document.getElementById("popupFeedback").classList.add("hidden");
}
