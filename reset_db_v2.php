<?php
try {
    $dsn = "pgsql:host=db.iuyapitbtdeoktaeqjai.supabase.co;dbname=postgres";
    $username = "postgres";
    $password = "Amigos4ever**";
    
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = "UPDATE users SET password = crypt('2026@Suporte', gen_salt('bf')) WHERE email = 'suportebuildcreators@gmail.com'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    echo "Reset executed successfully. Rows affected: " . $stmt->rowCount() . PHP_EOL;
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
