<?php
declare(strict_types=1);

// La racine du backend mène au panneau d'administration.
header('Location: admin/index.php');
http_response_code(302);
