    new Litepicker({ element: document.getElementById('weekFrom'), singleMode: true, format: 'MM/DD/YYYY' });
    new Litepicker({ element: document.getElementById('weekTo'), singleMode: true, format: 'MM/DD/YYYY' });

    function toMinutes(timeStr) {
        if (!timeStr) return null;
        const [h, m] = timeStr.split(':');
        return parseInt(h) * 60 + parseInt(m);
    }

    function updateTotals() {
        let weeklyTotal = 0;
        document.querySelectorAll('tbody tr').forEach(row => {
            const inTime = row.querySelector('input[name^="clockin"]')?.value;
            const outTime = row.querySelector('input[name^="clockout"]')?.value;
            const lunchOut = row.querySelector('input[name^="lunchout"]')?.value;
            const lunchIn = row.querySelector('input[name^="lunchin"]')?.value;

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
                row.querySelector('.total-cell').innerText = hours;
                weeklyTotal += parseFloat(hours);
            } else {
                const totalCell = row.querySelector('.total-cell');
                if(totalCell) {
                    totalCell.innerText = "0.00";
                }
            }
        });

        document.getElementById('weekly-total').innerText = weeklyTotal.toFixed(2) + "h";
        document.getElementById('weekly-overtime').innerText = (weeklyTotal > 40 ? (weeklyTotal - 40).toFixed(2) : "0.00") + "h";
    }

    document.querySelectorAll('input[type="time"]').forEach(input => {
        input.addEventListener('change', updateTotals);
    });

    updateTotals();
