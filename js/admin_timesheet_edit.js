document.addEventListener('DOMContentLoaded', function() {
    new Litepicker({
        element: document.getElementById('daterange'),
        singleMode: false,
        numberOfMonths: 1,
        numberOfColumns: 1,
        format: 'MM/DD/YYYY',
        maxDays: 31,
        dropdowns: {
            minYear: 2020,
            maxYear: null,
            months: true,
            years: true
        },
        autoApply: true,
        tooltipText: {
            one: 'day',
            other: 'days'
        },
        tooltipNumber: totalDays => totalDays - 1
    });

    const timesheetEditTable = document.querySelector('.timesheet-edit-table tbody');
    const btnAddNewPunch = document.getElementById('btn-add-new-punch');
    const btnSaveChanges = document.getElementById('btn-save-changes');

    // Modal elements
    const specificPunchDeleteModal = document.getElementById('specificPunchDeleteModal');
    const closeButton = specificPunchDeleteModal.querySelector('.close-btn');
    const confirmSpecificDeleteButton = document.getElementById('confirmSpecificDelete');
    const punchTypesCheckboxesDiv = document.getElementById('punch-types-checkboxes');
    let currentPunchIdToDelete = null; // To store the ID of the punch being modified

    function customAlert(message, onConfirm) {
        const customAlertModal = document.getElementById('customAlertModal');
        const customAlertMessage = document.getElementById('customAlertMessage');
        const customAlertActions = document.getElementById('customAlertActions');
        const customAlertConfirm = document.getElementById('customAlertConfirm');
        const customAlertCancel = document.getElementById('customAlertCancel');

        customAlertMessage.textContent = message;
        customAlertModal.style.display = 'block';

        if (onConfirm) {
            customAlertActions.style.display = 'block';
            customAlertConfirm.onclick = function() {
                onConfirm();
                customAlertModal.style.display = 'none';
            }
            customAlertCancel.onclick = function() {
                customAlertModal.style.display = 'none';
            }
        } else {
            customAlertActions.style.display = 'none';
        }

        const closeBtn = customAlertModal.querySelector('.close-btn');
        closeBtn.onclick = function() {
            customAlertModal.style.display = 'none';
        }
        window.onclick = function(event) {
            if (event.target == customAlertModal) {
                customAlertModal.style.display = 'none';
            }
        }
    }

    function toMinutes(timeStr) {
        if (!timeStr) return null;
        const [h, m] = timeStr.split(':');
        return parseInt(h) * 60 + parseInt(m);
    }

    function updateTotals() {
        let weeklyTotal = 0;
        document.querySelectorAll('tbody tr').forEach(row => {
            const inTime = row.querySelector('input[name="time_in"]')?.value;
            const outTime = row.querySelector('input[name="time_out"]')?.value;
            const lunchOut = row.querySelector('input[name="lunch_out"]')?.value;
            const lunchIn = row.querySelector('input[name="lunch_in"]')?.value;

            let totalMins = 0;
            const start = toMinutes(inTime);
            const end = toMinutes(outTime);

            if (start !== null && end !== null && end > start) {
                totalMins = end - start;
                const lOut = toMinutes(lunchOut);
                const lIn = toMinutes(lunchIn);
                if (lOut !== null && lIn !== null && lIn > lOut) {
                    totalMins -= (lIn - lOut);
                }
                const hours = (totalMins / 60).toFixed(2);
                row.querySelector('.total-hours').innerText = hours;
                weeklyTotal += parseFloat(hours);
            } else {
                const totalCell = row.querySelector('.total-hours');
                if(totalCell) {
                    totalCell.innerText = "0.00";
                }
            }
        });

        const weeklyTotalEl = document.getElementById('weekly-total');
        if (weeklyTotalEl) {
            weeklyTotalEl.innerText = weeklyTotal.toFixed(2) + "h";
        }
        const weeklyOvertimeEl = document.getElementById('weekly-overtime');
        if (weeklyOvertimeEl) {
            weeklyOvertimeEl.innerText = (weeklyTotal > 40 ? (weeklyTotal - 40).toFixed(2) : "0.00") + "h";
        }
    }

    // Event listener for input changes to recalculate total
    if(timesheetEditTable) {
        timesheetEditTable.addEventListener('change', function(event) {
            if (event.target.tagName === 'INPUT' && event.target.type === 'time') {
                const row = event.target.closest('tr');
                calculateRowTotal(row);
            }
        });
    }


    // Add New Punch button click handler
    if (btnAddNewPunch) {
        btnAddNewPunch.addEventListener('click', function() {
            const newRow = document.createElement('tr');
            newRow.innerHTML = `
                <td><input type="date" name="date" value="<?= date('Y-m-d') ?>"></td>
                <td><input type="time" name="time_in"></td>
                <td><input type="time" name="lunch_out"></td>
                <td><input type="time" name="lunch_in"></td>
                <td><input type="time" name="time_out"></td>
                <td><span class="total-hours" data-total-hours="0.00">00:00</span></td>
                <td>
                    <button class="btn-delete-punch new-punch">Delete</button>
                </td>
            `;
            timesheetEditTable.appendChild(newRow);
        });
    }

    // Event listener for clicks on the table
    if(timesheetEditTable) {
        timesheetEditTable.addEventListener('click', function(event) {
            // Delete Specific button click handler
            if (event.target.classList.contains('btn-delete-specific')) {
                const row = event.target.closest('tr');
                currentPunchIdToDelete = event.target.dataset.punchId;

                const date = row.querySelector('input[name="date"]').value;
                const timeIn = row.querySelector('input[name="time_in"]').value;
                const lunchOut = row.querySelector('input[name="lunch_out"]').value;
                const lunchIn = row.querySelector('input[name="lunch_in"]').value;
                const timeOut = row.querySelector('input[name="time_out"]').value;

                function formatTime(timeString) {
                    if (!timeString) return 'N/A';
                    const [hours, minutes] = timeString.split(':');
                    const h = parseInt(hours, 10);
                    const m = parseInt(minutes, 10);
                    const ampm = h >= 12 ? 'PM' : 'AM';
                    const h12 = h % 12 || 12;
                    return `${h12.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')} ${ampm}`;
                }

                const dateObj = new Date(date);
                const formattedDate = dateObj.toLocaleDateString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit' });

                const punchInfoDiv = specificPunchDeleteModal.querySelector('#punch-info');
                punchInfoDiv.innerHTML = `
                    <p><strong>Date:</strong> ${formattedDate}</p>
                    <p><strong>Clock In:</strong> ${formatTime(timeIn)}</p>
                    <p><strong>Lunch Out:</strong> ${formatTime(lunchOut)}</p>
                    <p><strong>Lunch In:</strong> ${formatTime(lunchIn)}</p>
                    <p><strong>Clock Out:</strong> ${formatTime(timeOut)}</p>
                `;

                specificPunchDeleteModal.style.display = 'block';
                punchTypesCheckboxesDiv.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
            }

            // Delete Punch button click handler
            if (event.target.classList.contains('btn-delete-punch')) {
                const row = event.target.closest('tr');
                const punchId = event.target.dataset.punchId;

                customAlert('Are you sure you want to delete this entire punch?', function() {
                    fetch('process_timesheet_edits.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ action: 'delete', id: punchId }),
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            customAlert('Punch deleted successfully!');
                            row.remove();
                        } else {
                            customAlert('Error deleting punch: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        customAlert('An error occurred while deleting the punch.');
                    });
                });
            }
        });
    }

    // Close modal
    if(closeButton) {
        closeButton.addEventListener('click', function() {
            specificPunchDeleteModal.style.display = 'none';
        });
    }

    if(specificPunchDeleteModal) {
        window.addEventListener('click', function(event) {
            if (event.target === specificPunchDeleteModal) {
                specificPunchDeleteModal.style.display = 'none';
            }
        });
    }

    // Confirm specific delete
    if(confirmSpecificDeleteButton) {
        confirmSpecificDeleteButton.addEventListener('click', function() {
            const fieldsToDelete = [];
            punchTypesCheckboxesDiv.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
                fieldsToDelete.push(checkbox.value);
            });

            if (currentPunchIdToDelete && fieldsToDelete.length > 0) {
                fetch('process_specific_punch_deletion.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: currentPunchIdToDelete, fields: fieldsToDelete }),
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        customAlert('Selected punch data deleted successfully!');
                        // Update UI: clear the fields in the table
                        const row = timesheetEditTable.querySelector(`tr[data-punch-id="${currentPunchIdToDelete}"]`);
                        if (row) {
                            fieldsToDelete.forEach(field => {
                                // Map field names to input names
                                let inputName = '';
                                if (field === 'TimeIN') inputName = 'time_in';
                                else if (field === 'LunchStart') inputName = 'lunch_out';
                                else if (field === 'LunchEnd') inputName = 'lunch_in';
                                else if (field === 'TimeOut') inputName = 'time_out';

                                const inputElement = row.querySelector(`input[name="${inputName}"]`);
                                if (inputElement) {
                                    inputElement.value = ''; // Clear the input field
                                }
                            });
                            // Recalculate total for the row after clearing fields
                            calculateRowTotal(row);
                        }
                        specificPunchDeleteModal.style.display = 'none';
                    } else {
                        customAlert('Error deleting specific punch data: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    customAlert('An error occurred while deleting specific punch data.');
                });
            } else {
                customAlert('Please select at least one punch type to delete.');
            }
        });
    }

    // Save Changes button click handler
    if (btnSaveChanges) {
        btnSaveChanges.addEventListener('click', function() {
            const punchesToSave = [];
            timesheetEditTable.querySelectorAll('tr').forEach(row => {
                const punchId = row.dataset.punchId || null; // null for new punches
                const date = row.querySelector('input[name="date"]').value;
                const timeIn = row.querySelector('input[name="time_in"]').value;
                const lunchOut = row.querySelector('input[name="lunch_out"]').value;
                const lunchIn = row.querySelector('input[name="lunch_in"]').value;
                const timeOut = row.querySelector('input[name="time_out"]').value;
                const totalHours = row.querySelector('.total-hours').dataset.totalHours; // Get decimal total

                punchesToSave.push({
                    id: punchId,
                    date: date,
                    time_in: timeIn,
                    lunch_out: lunchOut,
                    lunch_in: lunchIn,
                    time_out: timeOut,
                    total_hours: totalHours,
                });
            });

            fetch('process_timesheet_edits.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: 'save', emp: currentEmployeeID, punches: punchesToSave }),
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    customAlert('Changes saved successfully!');
                    // Optionally, reload the page or update the UI to reflect saved changes
                    // window.location.reload();
                } else {
                    customAlert('Error saving changes: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                customAlert('An error occurred while saving changes.');
            });
        });
    }

    // Initial calculation
    updateTotals();
});