<?php
declare(strict_types=1);

// Crée le premier administrateur : php cron/create_admin.php email@exemple.org
use App\Core\Database;
use App\Services\JournalService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage : php cron/create_admin.php email@exemple.org\n");
    exit(1);
}
fwrite(STDOUT, 'Mot de passe (12 caractères minimum) : ');
system('stty -echo 2>/dev/null');
$mdp = rtrim((string) fgets(STDIN), "\r\n");
system('stty echo 2>/dev/null');
fwrite(STDOUT, "\n");
if (strlen($mdp) < 12) {
    fwrite(STDERR, "Mot de passe trop court.\n");
    exit(1);
}
$hash = password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 12]);
$id = Database::value('SELECT id FROM utilisateurs WHERE email = ? AND telephone_whatsapp IS NULL', [$email]);
if ($id !== null) {
    Database::run("UPDATE utilisateurs SET mot_de_passe_hash = ?, role_global = 'admin', actif = 1 WHERE id = ?", [$hash, $id]);
} else {
    $id = Database::insert('utilisateurs', ['email' => $email, 'role_global' => 'admin', 'mot_de_passe_hash' => $hash]);
}
JournalService::ecrire(null, 'admin.creation', 'utilisateur', (int) $id);
echo "Administrateur prêt (id $id).\n";
