document.addEventListener('DOMContentLoaded', function() {
    const timesheetModeSelect = document.getElementById('timesheet-mode');

    // Function to get URL parameters
    function getUrlParameter(name) {
        name = name.replace(/[[\\]]/g, '\\$&amp;');
        var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)');
        var results = regex.exec(window.location.href);
        if (!results) return null;
        if (!results[2]) return '';
        return decodeURIComponent(results[2].replace(/\+/g, ' '));
    }

    function loadTimesheetContent(mode) {
        let url = '';
        const employeeID = getUrlParameter('emp');
        const fromDate = getUrlParameter('from');
        const toDate = getUrlParameter('to');

        let params = '';
        if (employeeID) params += `emp=${employeeID}`;
        if (fromDate) params += `${params ? '&' : ''}from=${fromDate}`;
        if (toDate) params += `${params ? '&' : ''}to=${toDate}`;

        if (mode === 'view') {
            url = 'timesheet_view.php';
        } else if (mode === 'edit') {
            url = 'timesheet_edit.php';
        } else if (mode === 'add') {
            url = 'timesheet_add.php';
        }

        if (url) {
            window.location.href = `${url}${params ? '?' + params : ''}`;
        }
    }

    if (timesheetModeSelect) {
        timesheetModeSelect.addEventListener('change', function() {
            loadTimesheetContent(this.value);
        });
    }

    // Set initial mode based on URL or default to 'view'
    const currentPath = window.location.pathname;
    if (currentPath.includes('timesheet_edit.php')) {
        if (timesheetModeSelect) timesheetModeSelect.value = 'edit';
    } else if (currentPath.includes('timesheet_add.php')) {
        if (timesheetModeSelect) timesheetModeSelect.value = 'add';
    } else {
        if (timesheetModeSelect) timesheetModeSelect.value = 'view';
    }
});
