<?php
/**
 * Plugin Name: TNP Eventi
 * Description: Iscrizioni agli eventi da Contact Form 7 e promemoria programmati in The Newsletter Plugin.
 * Version: 0.2.2
 * Author: Paolo Subioli
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Requires Plugins: newsletter, contact-form-7
 */

require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v6\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/paolosub/tnp-eventi/',
    __FILE__,
    'tnp-eventi'
);

$myUpdateChecker->setBranch('main');
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

defined('ABSPATH') || exit;

const TNP_EVENTI_OPZIONE_LISTA = 'tnp_eventi_lista_newsletter';
const TNP_EVENTI_OPZIONE_ESCLUSI = 'tnp_eventi_esclusi';
const TNP_EVENTI_MAX_ESCLUSI = 200;

require_once __DIR__ . '/eventi.php';

/**
 * Controlla che The Newsletter Plugin esponga ancora ciò che usiamo: sono funzioni
 * interne e un aggiornamento può cambiarle. Se manca qualcosa il plugin si ferma.
 *
 * @return true|WP_Error
 */
function tnp_eventi_verifica() {
    if (!class_exists('Newsletter') || !class_exists('TNP_User') || !class_exists('TNP_Email')) {
        return new WP_Error('tnp_eventi_dipendenze', 'The Newsletter Plugin non è attivo.', ['status' => 503]);
    }
    foreach (['save_email', 'get_email', 'delete_email', 'set_email_status', 'save_user', 'get_user_by_email', 'get_lists'] as $metodo) {
        if (!method_exists('Newsletter', $metodo)) {
            return new WP_Error('tnp_eventi_dipendenze', "Manca Newsletter::$metodo: controllare dopo l'aggiornamento.", ['status' => 503]);
        }
    }
    foreach (['NEWSLETTER_EMAILS_TABLE', 'NEWSLETTER_USERS_TABLE', 'NEWSLETTER_LIST_MAX'] as $costante) {
        if (!defined($costante)) {
            return new WP_Error('tnp_eventi_dipendenze', "Manca la costante $costante: controllare dopo l'aggiornamento.", ['status' => 503]);
        }
    }
    return true;
}

/**
 * La lista della newsletter generica: non si usa per i promemoria e non si svuota.
 * Vale 0 finché non viene scelta nelle impostazioni.
 */
function tnp_eventi_lista_newsletter() {
    return (int) get_option(TNP_EVENTI_OPZIONE_LISTA, 0);
}

/**
 * Promemoria e svuotamento partono solo con la lista generica scelta: senza, non si può proteggerla.
 *
 * @return true|WP_Error
 */
function tnp_eventi_verifica_completa() {
    $verifica = tnp_eventi_verifica();
    if (is_wp_error($verifica)) {
        return $verifica;
    }
    if (tnp_eventi_lista_newsletter() === 0) {
        return new WP_Error('tnp_eventi_impostazioni', 'Scegliere la lista della newsletter in Impostazioni > TNP Eventi.', ['status' => 409]);
    }
    return true;
}

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    $verifica = tnp_eventi_verifica_completa();
    if (is_wp_error($verifica)) {
        echo '<div class="notice notice-error"><p><strong>TNP Eventi è fermo.</strong> ' . esc_html($verifica->get_error_message()) . '</p></div>';
    }
});

/* Impostazioni */

add_action('admin_init', function () {
    register_setting('tnp_eventi', TNP_EVENTI_OPZIONE_LISTA, [
        'type' => 'integer',
        'default' => 0,
        'sanitize_callback' => function ($valore) {
            $valore = (int) $valore;
            return defined('NEWSLETTER_LIST_MAX') && $valore >= 1 && $valore <= NEWSLETTER_LIST_MAX ? $valore : 0;
        },
    ]);
    register_setting('tnp_eventi', 'tnp_eventi_url_privacy', ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
    register_setting('tnp_eventi', 'tnp_eventi_url_condizioni', ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
    register_setting('tnp_eventi', 'tnp_eventi_testo_casella', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
    register_setting('tnp_eventi', 'tnp_eventi_colore', ['type' => 'string', 'sanitize_callback' => 'sanitize_hex_color']);
});

add_action('admin_menu', function () {
    add_options_page('TNP Eventi', 'TNP Eventi', 'manage_options', 'tnp-eventi-impostazioni', 'tnp_eventi_pagina_impostazioni');
});

function tnp_eventi_pagina_impostazioni() {
    if (is_wp_error(tnp_eventi_verifica())) {
        echo '<div class="wrap"><h1>TNP Eventi</h1><p>The Newsletter Plugin non è disponibile.</p></div>';
        return;
    }

    $nomi = [];
    foreach (Newsletter::instance()->get_lists() as $lista) {
        $nomi[(int) $lista->id] = $lista->name;
    }
    $scelta = tnp_eventi_lista_newsletter();
    ?>
    <div class="wrap">
        <h1>TNP Eventi</h1>
        <form method="post" action="options.php">
            <?php settings_fields('tnp_eventi'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tnp-eventi-lista">Lista della newsletter</label></th>
                    <td>
                        <select id="tnp-eventi-lista" name="<?php echo esc_attr(TNP_EVENTI_OPZIONE_LISTA); ?>">
                            <option value="0">Da scegliere</option>
                            <?php for ($id = 1; $id <= NEWSLETTER_LIST_MAX; $id++) { ?>
                                <option value="<?php echo (int) $id; ?>" <?php selected($scelta, $id); ?>><?php echo (int) $id . ' - ' . esc_html($nomi[$id] ?? '(senza nome)'); ?></option>
                            <?php } ?>
                        </select>
                        <p class="description">Chi spunta la casella nel modulo entra in questa lista. Non si può usare per i promemoria e non si può svuotare.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tnp-eventi-privacy">Pagina della Privacy Policy</label></th>
                    <td><input class="regular-text" id="tnp-eventi-privacy" type="url" name="tnp_eventi_url_privacy" value="<?php echo esc_attr((string) get_option('tnp_eventi_url_privacy', '')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tnp-eventi-condizioni">Pagina delle condizioni di prenotazione</label></th>
                    <td><input class="regular-text" id="tnp-eventi-condizioni" type="url" name="tnp_eventi_url_condizioni" value="<?php echo esc_attr((string) get_option('tnp_eventi_url_condizioni', '')); ?>">
                        <p class="description">Facoltativa: se resta vuota, il modulo non chiede di accettare le condizioni.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tnp-eventi-casella">Testo della casella newsletter</label></th>
                    <td><input class="regular-text" id="tnp-eventi-casella" type="text" name="tnp_eventi_testo_casella" value="<?php echo esc_attr((string) get_option('tnp_eventi_testo_casella', '')); ?>" placeholder="Voglio ricevere anche la newsletter."></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tnp-eventi-colore">Colore del modulo</label></th>
                    <td><input id="tnp-eventi-colore" type="color" name="tnp_eventi_colore" value="<?php echo esc_attr(sanitize_hex_color((string) get_option('tnp_eventi_colore', '')) ?: '#e59a3a'); ?>"></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function tnp_eventi_lista_valida($lista) {
    $lista = (int) $lista;
    return $lista >= 1 && $lista <= NEWSLETTER_LIST_MAX;
}

function tnp_eventi_query_lista($lista) {
    return 'select * from ' . NEWSLETTER_USERS_TABLE . " where status='C' and (list_" . (int) $lista . '=1)';
}

/**
 * Una query senza filtro sulla lista manderebbe la newsletter a tutti gli iscritti confermati.
 */
function tnp_eventi_query_sicura($query) {
    return is_string($query) && preg_match('/ and \(list_\d+=1\)$/', $query) === 1;
}

function tnp_eventi_registra_escluso($lista, $email, $motivo) {
    $esclusi = get_option(TNP_EVENTI_OPZIONE_ESCLUSI, []);
    $esclusi[$lista][] = ['email' => $email, 'motivo' => $motivo, 'data' => gmdate('c')];
    $esclusi[$lista] = array_slice($esclusi[$lista], -TNP_EVENTI_MAX_ESCLUSI);
    update_option(TNP_EVENTI_OPZIONE_ESCLUSI, $esclusi, false);
}

/* Iscrizioni dai moduli di Contact Form 7 */

add_action('wpcf7_before_send_mail', 'tnp_eventi_gestisci_iscrizione', 10, 3);

function tnp_eventi_gestisci_iscrizione($modulo, &$abort, $invio) {
    $impostazione = $modulo->additional_setting('tnp_lista_evento');
    if (empty($impostazione)) {
        return;
    }
    if (is_wp_error(tnp_eventi_verifica())) {
        return;
    }

    $lista = (int) $impostazione[0];
    $generica = tnp_eventi_lista_newsletter();
    if (!tnp_eventi_lista_valida($lista) || $lista === $generica) {
        return;
    }

    // Un modulo può mandare il consenso a una lista sua, invece che alla newsletter generica.
    $propria = (int) ($modulo->additional_setting('tnp_lista_consenso')[0] ?? 0);
    if ($propria > 0 && $propria !== $lista && tnp_eventi_lista_valida($propria)) {
        $generica = $propria;
    }

    $dati = $invio->get_posted_data();
    $campo_email = $modulo->additional_setting('tnp_campo_email')[0] ?? 'your-email';
    $campo_nome = $modulo->additional_setting('tnp_campo_nome')[0] ?? 'your-name';
    $campo_cognome = $modulo->additional_setting('tnp_campo_cognome')[0] ?? 'your-surname';
    $campo_consenso = $modulo->additional_setting('tnp_campo_consenso')[0] ?? 'newsletter';

    $email = sanitize_email((string) ($dati[$campo_email] ?? ''));
    if (!is_email($email)) {
        return;
    }

    $newsletter = Newsletter::instance();
    $liste = ['list_' . $lista => 1];

    // La casella è facoltativa: senza spunta la persona entra solo nella lista dell'evento.
    if (!empty($dati[$campo_consenso])) {
        if ($generica > 0) {
            $liste['list_' . $generica] = 1;
        } else {
            tnp_eventi_registra_escluso($lista, $email, 'consenso non registrato: lista della newsletter da scegliere');
        }
    }

    $iscritto = $newsletter->get_user_by_email($email);

    if (!$iscritto) {
        $nuovo = $liste + [
            'email' => $email,
            'name' => sanitize_text_field((string) ($dati[$campo_nome] ?? '')),
            'surname' => sanitize_text_field((string) ($dati[$campo_cognome] ?? '')),
            'status' => TNP_User::STATUS_CONFIRMED,
        ];
        $salvato = $newsletter->save_user($nuovo);
    } elseif ($iscritto->status === TNP_User::STATUS_CONFIRMED || $iscritto->status === TNP_User::STATUS_NOT_CONFIRMED) {
        // Solo le liste e lo stato: i dati dell'iscritto non cambiano senza una sua conferma.
        $salvato = $newsletter->save_user($liste + ['id' => $iscritto->id, 'status' => TNP_User::STATUS_CONFIRMED]);
    } else {
        // Disiscritti, email respinte, segnalazioni di spam: non si riattivano da un modulo.
        tnp_eventi_registra_escluso($lista, $email, 'stato ' . $iscritto->status);
        return;
    }

    if (!$salvato || is_wp_error($salvato)) {
        tnp_eventi_registra_escluso($lista, $email, 'errore di salvataggio');
        return;
    }

    $newsletter->add_user_log($salvato, 'tnp-eventi-lista-' . $lista);
}

/* Promemoria e liste via REST */

add_action('rest_api_init', function () {
    $permesso = function () {
        return current_user_can('manage_options');
    };

    register_rest_route('tnp-eventi/v1', '/promemoria', [
        [
            'methods' => 'POST',
            'callback' => 'tnp_eventi_crea_promemoria',
            'permission_callback' => $permesso,
        ],
        [
            'methods' => 'GET',
            'callback' => 'tnp_eventi_elenca_promemoria',
            'permission_callback' => $permesso,
        ],
    ]);
    register_rest_route('tnp-eventi/v1', '/promemoria/(?P<id>\d+)/programma', [
        'methods' => 'POST',
        'callback' => 'tnp_eventi_programma_promemoria',
        'permission_callback' => $permesso,
    ]);
    register_rest_route('tnp-eventi/v1', '/promemoria/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'tnp_eventi_annulla_promemoria',
        'permission_callback' => $permesso,
    ]);
    register_rest_route('tnp-eventi/v1', '/liste', [
        'methods' => 'GET',
        'callback' => 'tnp_eventi_elenca_liste',
        'permission_callback' => $permesso,
    ]);
    register_rest_route('tnp-eventi/v1', '/liste/(?P<lista>\d+)/svuota', [
        'methods' => 'POST',
        'callback' => 'tnp_eventi_svuota_lista',
        'permission_callback' => $permesso,
    ]);
});

/**
 * Crea il promemoria come bozza: parte solo dopo l'approvazione.
 */
function tnp_eventi_crea_promemoria(WP_REST_Request $richiesta) {
    $verifica = tnp_eventi_verifica_completa();
    if (is_wp_error($verifica)) {
        return $verifica;
    }

    $lista = (int) $richiesta->get_param('lista');
    $evento = sanitize_text_field((string) $richiesta->get_param('evento'));
    $oggetto = sanitize_text_field((string) $richiesta->get_param('oggetto'));
    $html = (string) $richiesta->get_param('html');

    if (!tnp_eventi_lista_valida($lista) || $lista === tnp_eventi_lista_newsletter()) {
        return new WP_Error('tnp_eventi_lista', 'Serve una lista di evento valida, diversa da quella della newsletter.', ['status' => 400]);
    }
    if ($evento === '' || $oggetto === '' || trim($html) === '') {
        return new WP_Error('tnp_eventi_dati', 'Servono evento, oggetto e html.', ['status' => 400]);
    }

    try {
        $data = new DateTimeImmutable((string) $richiesta->get_param('data'), wp_timezone());
    } catch (Exception $errore) {
        return new WP_Error('tnp_eventi_data', 'Data non valida.', ['status' => 400]);
    }
    if (empty($richiesta->get_param('data')) || $data->getTimestamp() <= time()) {
        return new WP_Error('tnp_eventi_data', 'La data di invio deve essere nel futuro.', ['status' => 400]);
    }

    $promemoria = tnp_eventi_nuovo_promemoria($lista, $evento, $oggetto, $html, $data->getTimestamp(), (string) $richiesta->get_param('testo'));
    if (is_wp_error($promemoria)) {
        return $promemoria;
    }

    return tnp_eventi_descrivi_promemoria($promemoria);
}

/**
 * Crea un promemoria come bozza.
 *
 * @return object|WP_Error
 */
function tnp_eventi_nuovo_promemoria($lista, $evento, $oggetto, $html, $data, $testo = '', $altre_opzioni = []) {
    global $wpdb;
    $newsletter = Newsletter::instance();
    $query = tnp_eventi_query_lista($lista);

    $promemoria = $newsletter->save_email([
        'status' => TNP_Email::STATUS_DRAFT,
        'type' => 'message',
        'editor' => TNP_Email::EDITOR_HTML,
        'subject' => $oggetto,
        'message' => $html,
        'message_text' => $testo,
        'track' => $newsletter->get_option('track'),
        'token' => $newsletter::get_token(),
        'send_on' => $data,
        'query' => $query,
        'total' => (int) $wpdb->get_var(str_replace('*', 'count(*)', $query)),
        // Gli stessi valori del pannello, così la newsletter resta modificabile da lì.
        'options' => $altre_opzioni + [
            'status' => 'C',
            'wp_users' => '0',
            'lists' => [$lista],
            'lists_operator' => 'or',
            'tnp_evento' => $evento,
            // Salva nel pannello riscrive send_on con l'ora corrente: qui resta la data decisa.
            'tnp_data' => $data,
        ],
    ]);

    if (!$promemoria || is_wp_error($promemoria)) {
        return new WP_Error('tnp_eventi_salvataggio', 'Promemoria non salvato.', ['status' => 500]);
    }

    return $promemoria;
}

function tnp_eventi_programma_promemoria(WP_REST_Request $richiesta) {
    $verifica = tnp_eventi_verifica();
    if (is_wp_error($verifica)) {
        return $verifica;
    }
    $esito = tnp_eventi_programma((int) $richiesta['id']);
    return is_wp_error($esito) ? $esito : tnp_eventi_descrivi_promemoria(Newsletter::instance()->get_email((int) $richiesta['id']));
}

/**
 * @return true|WP_Error
 */
function tnp_eventi_programma($id) {
    $newsletter = Newsletter::instance();
    $promemoria = tnp_eventi_carica_promemoria($id);
    if (is_wp_error($promemoria)) {
        return $promemoria;
    }
    if ($promemoria->status !== TNP_Email::STATUS_DRAFT) {
        return new WP_Error('tnp_eventi_stato', 'Si può programmare solo una bozza.', ['status' => 409]);
    }
    $opzioni = maybe_unserialize($promemoria->options);
    $data = (int) ($opzioni['tnp_data'] ?? $promemoria->send_on);
    if ($data <= time()) {
        return new WP_Error('tnp_eventi_data', 'La data di invio è già passata.', ['status' => 409]);
    }
    if (!tnp_eventi_query_sicura($promemoria->query)) {
        return new WP_Error('tnp_eventi_destinatari', 'I destinatari non sono limitati a una lista: invio bloccato.', ['status' => 409]);
    }

    if ($data !== (int) $promemoria->send_on) {
        $newsletter->save_email(['id' => $promemoria->id, 'send_on' => $data]);
    }
    $newsletter->set_email_status($promemoria, TNP_Email::STATUS_SENDING);
    return true;
}

function tnp_eventi_annulla_promemoria(WP_REST_Request $richiesta) {
    $verifica = tnp_eventi_verifica();
    if (is_wp_error($verifica)) {
        return $verifica;
    }
    $esito = tnp_eventi_annulla((int) $richiesta['id']);
    return is_wp_error($esito) ? $esito : ['annullato' => (int) $richiesta['id']];
}

/**
 * @return true|WP_Error
 */
function tnp_eventi_annulla($id) {
    $promemoria = tnp_eventi_carica_promemoria($id);
    if (is_wp_error($promemoria)) {
        return $promemoria;
    }

    $in_attesa = $promemoria->status === TNP_Email::STATUS_DRAFT
            || ($promemoria->status === TNP_Email::STATUS_SENDING && (int) $promemoria->sent === 0 && (int) $promemoria->send_on > time());
    if (!$in_attesa) {
        return new WP_Error('tnp_eventi_stato', 'Il promemoria è già partito.', ['status' => 409]);
    }

    Newsletter::instance()->delete_email($promemoria->id);
    return true;
}

/**
 * Carica solo newsletter create da questo plugin, per non toccare le altre.
 */
function tnp_eventi_carica_promemoria($id) {
    $promemoria = Newsletter::instance()->get_email((int) $id);
    if (!$promemoria) {
        return new WP_Error('tnp_eventi_assente', 'Promemoria non trovato.', ['status' => 404]);
    }
    $opzioni = maybe_unserialize($promemoria->options);
    if (empty($opzioni['tnp_evento'])) {
        return new WP_Error('tnp_eventi_assente', 'Questa newsletter non è un promemoria di TNP Eventi.', ['status' => 404]);
    }
    return $promemoria;
}

/**
 * Gli ultimi promemoria creati da questo plugin.
 */
function tnp_eventi_elenca_promemoria() {
    $verifica = tnp_eventi_verifica();
    if (is_wp_error($verifica)) {
        return $verifica;
    }

    global $wpdb;
    $righe = $wpdb->get_results($wpdb->prepare(
                    'select * from ' . NEWSLETTER_EMAILS_TABLE . ' where options like %s order by id desc limit 50',
                    '%' . $wpdb->esc_like('tnp_evento') . '%'
    ));
    return array_map('tnp_eventi_descrivi_promemoria', $righe);
}

function tnp_eventi_descrivi_promemoria($promemoria) {
    $opzioni = maybe_unserialize($promemoria->options);
    // Su una bozza salvata dal pannello send_on è l'ora del salvataggio: conta la data decisa.
    $data = $promemoria->status === TNP_Email::STATUS_DRAFT ? ($opzioni['tnp_data'] ?? $promemoria->send_on) : $promemoria->send_on;
    return [
        'id' => (int) $promemoria->id,
        'evento' => $opzioni['tnp_evento'] ?? '',
        'oggetto' => $promemoria->subject,
        'stato' => $promemoria->status,
        'data' => wp_date('c', (int) $data),
        'destinatari' => (int) $promemoria->total,
    ];
}

/**
 * Newsletter non ancora concluse che usano la lista: partirebbero verso gli iscritti del nuovo evento.
 */
function tnp_eventi_newsletter_in_attesa($lista) {
    global $wpdb;
    $filtro = '%' . $wpdb->esc_like('list_' . (int) $lista . '=1') . '%';
    return $wpdb->get_results($wpdb->prepare(
                            'select id, subject, status from ' . NEWSLETTER_EMAILS_TABLE . " where status in ('new', 'sending', 'paused') and query like %s",
                            $filtro
    ));
}

function tnp_eventi_elenca_liste() {
    $verifica = tnp_eventi_verifica();
    if (is_wp_error($verifica)) {
        return $verifica;
    }

    global $wpdb;
    $esclusi = get_option(TNP_EVENTI_OPZIONE_ESCLUSI, []);
    $nomi = [];
    foreach (Newsletter::instance()->get_lists() as $lista) {
        $nomi[(int) $lista->id] = $lista->name;
    }

    // Tutti i numeri, non solo quelli con un nome: anche una lista senza nome può avere iscritti.
    $risposta = [];
    for ($id = 1; $id <= NEWSLETTER_LIST_MAX; $id++) {
        $risposta[] = [
            'lista' => $id,
            'nome' => $nomi[$id] ?? '',
            'newsletter_generica' => $id === tnp_eventi_lista_newsletter(),
            'iscritti' => (int) $wpdb->get_var('select count(*) from ' . NEWSLETTER_USERS_TABLE . " where status='C' and list_$id=1"),
            'in_attesa' => count(tnp_eventi_newsletter_in_attesa($id)),
            'esclusi' => $esclusi[$id] ?? [],
        ];
    }
    return $risposta;
}

/**
 * Svuota una lista per riusarla: va chiesto con conferma uguale al numero della lista.
 */
function tnp_eventi_svuota_lista(WP_REST_Request $richiesta) {
    $verifica = tnp_eventi_verifica_completa();
    if (is_wp_error($verifica)) {
        return $verifica;
    }

    $lista = (int) $richiesta['lista'];
    if (!tnp_eventi_lista_valida($lista) || $lista === tnp_eventi_lista_newsletter()) {
        return new WP_Error('tnp_eventi_lista', 'Questa lista non si può svuotare.', ['status' => 400]);
    }
    if ((int) $richiesta->get_param('conferma') !== $lista) {
        return new WP_Error('tnp_eventi_conferma', 'Serve conferma uguale al numero della lista.', ['status' => 400]);
    }

    return tnp_eventi_svuota($lista);
}

/**
 * @return array|WP_Error
 */
function tnp_eventi_svuota($lista) {
    if (!tnp_eventi_lista_valida($lista) || $lista === tnp_eventi_lista_newsletter()) {
        return new WP_Error('tnp_eventi_lista', 'Questa lista non si può svuotare.', ['status' => 400]);
    }

    $in_attesa = tnp_eventi_newsletter_in_attesa($lista);
    if ($in_attesa) {
        return new WP_Error('tnp_eventi_in_attesa', 'Ci sono email in attesa su questa lista: prima devono partire, oppure vanno eliminate.', ['status' => 409, 'newsletter' => $in_attesa]);
    }

    global $wpdb;
    $tolti = $wpdb->query('update ' . NEWSLETTER_USERS_TABLE . " set list_$lista=0 where list_$lista=1");

    $esclusi = get_option(TNP_EVENTI_OPZIONE_ESCLUSI, []);
    unset($esclusi[$lista]);
    update_option(TNP_EVENTI_OPZIONE_ESCLUSI, $esclusi, false);

    return ['lista' => $lista, 'tolti' => (int) $tolti];
}
