<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
    logoutUser();
    header('Location: user-login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Calendar Debug - <?php echo SITE_NAME; ?></title>
</head>
<body>
    <h1>Event Calendar Debug Page</h1>

    <h2>PHP User Data:</h2>
    <pre><?php echo json_encode($currentUser, JSON_PRETTY_PRINT); ?></pre>

    <h2>Session Data:</h2>
    <pre><?php echo json_encode($_SESSION, JSON_PRETTY_PRINT); ?></pre>

    <h2>JavaScript User Data:</h2>
    <script>
        // Pass user data to JavaScript
        window.currentUser = <?php
            // Convert snake_case to camelCase for JavaScript
            $jsUser = $currentUser;
            $jsUser['firstName'] = $currentUser['first_name'];
            $jsUser['lastName'] = $currentUser['last_name'];
            echo json_encode($jsUser);
        ?>;
        window.isAdmin = <?php echo ($currentUser['role'] === 'admin') ? 'true' : 'false'; ?>;

        console.log('JavaScript user data:', window.currentUser);
        document.write('<pre>' + JSON.stringify(window.currentUser, null, 2) + '</pre>');
    </script>

    <h2>DOM Elements Check:</h2>
    <div id="debug-output"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const debugOutput = document.getElementById('debug-output');
            debugOutput.innerHTML = `
                <p>currentMonth element: ${document.getElementById('currentMonth') ? 'Found' : 'NOT FOUND'}</p>
                <p>calendarDays element: ${document.getElementById('calendarDays') ? 'Found' : 'NOT FOUND'}</p>
                <p>userDisplayName element: ${document.getElementById('userDisplayName') ? 'Found' : 'NOT FOUND'}</p>
            `;
        });
    </script>

    <p><a href="event-calendar.php">Back to Event Calendar</a></p>
</body>
</html>