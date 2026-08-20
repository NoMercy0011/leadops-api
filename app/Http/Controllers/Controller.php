<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 11 a retiré ce trait de la classe de base. On le remet : sur un
    // produit multi-tenant, `$this->authorize()` doit être disponible partout
    // sans y penser, faute de quoi la tentation est grande d'écrire un `if`
    // maison qui oublie un cas.
    use AuthorizesRequests;
}
