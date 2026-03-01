<?php
/**
 * Universal Document Status Template
 */
$statusColors = [
    'approved' => '#10b981',
    'rejected' => '#ef4444',
    'pending'  => '#f59e0b'
];
$color = $statusColors[strtolower($status)] ?? '#3b82f6';
?>
<h2 style="color: #333; margin-bottom: 10px;">Update on Your Document</h2>
<p style="color: #555; font-size: 16px;">
    Hello <strong><?php echo htmlspecialchars($recipientName); ?></strong>,
</p>
<div style="background-color: #f9fafb; border-left: 4px solid <?php echo $color; ?>; padding: 20px; margin: 25px 0;">
    <p style="margin: 0; font-size: 14px; color: #6b7280; text-transform: uppercase; font-weight: bold;">Document Title</p>
    <p style="margin: 5px 0 15px 0; font-size: 18px; font-weight: bold; color: #111827;"><?php echo htmlspecialchars($documentTitle); ?></p>
    
    <p style="margin: 0; font-size: 14px; color: #6b7280; text-transform: uppercase; font-weight: bold;">Status Update</p>
    <p style="margin: 5px 0 0 0; font-size: 16px; font-weight: bold; color: <?php echo $color; ?>;"><?php echo strtoupper($status); ?></p>
</div>

<?php if (!empty($message)): ?>
<p style="color: #555; line-height: 1.6; font-style: italic;">
    "<?php echo htmlspecialchars($message); ?>"
</p>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="<?php echo BASE_URL; ?>" style="background-color: #1259c3; color: white; padding: 12px 25px; text-decoration: none; border-radius: 50px; font-weight: bold; display: inline-block;">
        View Document in Portal
    </a>
</div>