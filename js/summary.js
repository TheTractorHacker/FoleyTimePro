    document.addEventListener('DOMContentLoaded', function () {
        new Litepicker({
            element: document.getElementById('dateRange'),
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
    });
