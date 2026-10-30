<?php
// Comptes du back-office : data/utilisateurs.json (jamais dans Git, jamais lisible depuis le navigateur)
// Rôles : « admin » (tout, y compris les comptes) et « editeur » (contenu du site uniquement).
const COMPTES = DATA . '/utilisateurs.json';
const ROLES = ['admin' => 'Administrateur', 'editeur' => 'Éditeur'];

function comptes_charger() {
    $d = is_file(COMPTES) ? json_decode((string)file_get_contents(COMPTES), true) : null;
    return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
}
function comptes_enregistrer($l) { return json_save(COMPTES, array_values($l)); }
function compte_par_id($id) {
    foreach (comptes_charger() as $u) if (($u['id'] ?? '') === $id) return $u;
    return null;
}
// Connexion avec l'identifiant OU l'adresse e-mail (sans tenir compte des majuscules)
function compte_par_login($login) {
    $login = strtolower(trim((string)$login));
    if ($login === '') return null;
    foreach (comptes_charger() as $u) if (strtolower($u['identifiant'] ?? '') === $login || strtolower($u['email'] ?? '') === $login) return $u;
    return null;
}
function compte_public($u) {
    return ['id' => $u['id'], 'identifiant' => $u['identifiant'], 'email' => $u['email'], 'nom' => $u['nom'] ?? '',
            'role' => $u['role'], 'cree' => $u['cree'] ?? '', 'derniere' => $u['derniere'] ?? ''];
}
function nb_admins($l) { return count(array_filter($l, function ($u) { return ($u['role'] ?? '') === 'admin'; })); }

// Vérifie et prépare un compte à créer ou modifier. Renvoie [compte, erreur].
function compte_valider($in, $existant, $tous) {
    $id = $existant['id'] ?? bin2hex(random_bytes(6));
    $u = [
        'id' => $id,
        'identifiant' => strtolower(trim((string)($in['identifiant'] ?? ''))),
        'email' => strtolower(trim((string)($in['email'] ?? ''))),
        'nom' => txt($in['nom'] ?? '', 60),
        'role' => array_key_exists($in['role'] ?? '', ROLES) ? $in['role'] : 'editeur',
        'hash' => $existant['hash'] ?? '',
        'cree' => $existant['cree'] ?? date('Y-m-d'),
        'derniere' => $existant['derniere'] ?? '',
    ];
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $u['identifiant'])) return [null, 'L’identifiant doit faire 3 à 30 caractères : lettres, chiffres, point, tiret (sans espace ni accent).'];
    if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) return [null, 'L’adresse e-mail n’est pas valide.'];
    foreach ($tous as $o) {
        if ($o['id'] === $id) continue;
        if ($o['identifiant'] === $u['identifiant']) return [null, 'Cet identifiant est déjà utilisé.'];
        if ($o['email'] === $u['email']) return [null, 'Cette adresse e-mail est déjà utilisée.'];
    }
    $mdp = (string)($in['mdp'] ?? '');
    if ($mdp !== '' || !$existant) {
        if ($e = mdp_faible($mdp)) return [null, $e];
        $u['hash'] = password_hash($mdp, PASSWORD_DEFAULT);
    }
    return [$u, null];
}
function mdp_faible($mdp) {
    if (strlen($mdp) < 10) return 'Le mot de passe doit faire au moins 10 caractères.';
    if (!preg_match('/[a-zA-Z]/', $mdp) || !preg_match('/[0-9]/', $mdp)) return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
    return null;
}

// Limite les essais de connexion : 5 échecs par adresse en 15 minutes
function tentatives($ajouter = null) {
    $f = DATA . '/tentatives.json';
    $t = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    $t = is_array($t) ? array_filter($t, function ($x) { return time() - ($x['t'] ?? 0) < 900; }) : [];
    $k = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'jp'), 0, 16);
    if ($ajouter === true) { $t[$k] = ['n' => ($t[$k]['n'] ?? 0) + 1, 't' => time()]; json_save($f, $t); }
    if ($ajouter === false) { unset($t[$k]); json_save($f, $t); }
    return $t[$k]['n'] ?? 0;
}
