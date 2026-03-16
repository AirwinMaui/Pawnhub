<?php
// I-run ito sa browser: http://localhost/pawnshop_ab/genhash.php
// Pagkatapos makuha ang hash, i-delete na ang file na ito!

$passwords = [
    'SuperAdmin123',
    'Admin123',
    'Staff123',
    'Cashier123',
];

echo '<h2 style="font-family:sans-serif;padding:20px;">PawnHub — Password Hash Generator</h2>';
echo '<table border="1" cellpadding="10" style="font-family:monospace;border-collapse:collapse;margin:20px;">';
echo '<tr style="background:#f0f0f0;"><th>Password</th><th>Hash (paste sa SQL)</th></tr>';

foreach ($passwords as $pw) {
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    echo "<tr><td><b>$pw</b></td><td style='font-size:12px;'>$hash</td></tr>";
}

echo '</table>';
echo '<p style="font-family:sans-serif;padding:20px;color:red;"><b>⚠️ I-DELETE agad ang file na ito pagkatapos!</b></p>';

// Auto-generate SQL update
echo '<h3 style="font-family:sans-serif;padding:0 20px;">SQL para i-update ang super admin:</h3>';
$superHash = password_hash('SuperAdmin123', PASSWORD_BCRYPT);
echo "<pre style='background:#f8f8f8;padding:20px;margin:20px;border:1px solid #ddd;'>";
echo "USE pawnshop_im;\n\n";
echo "UPDATE users\n";
echo "SET password = '$superHash'\n";
echo "WHERE username = 'superadmin';\n";
echo "</pre>";
?>