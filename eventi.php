<?php
/**
 * La pagina "Eventi" della bacheca: il cliente crea un evento e ottiene lista, modulo e promemoria.
 */

defined('ABSPATH') || exit;

const TNP_EVENTI_POST_TYPE = 'tnp_evento';

add_action('init', function () {
    register_post_type(TNP_EVENTI_POST_TYPE, ['public' => false, 'show_ui' => false, 'label' => 'Eventi', 'supports' => ['title']]);
});

function tnp_eventi_permesso() {
    return apply_filters('tnp_eventi_permesso', TNP_EVENTI_PERMESSO);
}

/* Liste */

function tnp_eventi_nomi_liste() {
    $liste = get_option('newsletter_lists', []);
    return is_array($liste) ? $liste : [];
}

function tnp_eventi_nomina_lista($lista, $nome) {
    $liste = tnp_eventi_nomi_liste();
    if ($nome === '') {
        unset($liste['list_' . $lista], $liste['list_' . $lista . '_status']);
    } else {
        $liste['list_' . $lista] = $nome;
    }
    update_option('newsletter_lists', $liste, true);
}

function tnp_eventi_eventi($anche_chiusi = true) {
    $eventi = get_posts(['post_type' => TNP_EVENTI_POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'meta_value_num', 'meta_key' => '_tnp_data', 'order' => 'DESC']);
    return $anche_chiusi ? $eventi : array_values(array_filter($eventi, function ($evento) {
                return !get_post_meta($evento->ID, '_tnp_chiuso', true);
            }));
}

/**
 * Una lista è libera solo se è senza nome, senza nessun iscritto e senza newsletter:
 * le liste già in uso sul sito non si toccano.
 */
function tnp_eventi_lista_libera() {
    global $wpdb;
    $nomi = tnp_eventi_nomi_liste();
    $occupate = [tnp_eventi_lista_newsletter()];
    foreach (tnp_eventi_eventi(false) as $evento) {
        $occupate[] = (int) get_post_meta($evento->ID, '_tnp_lista', true);
    }
    for ($id = 1; $id <= NEWSLETTER_LIST_MAX; $id++) {
        if (in_array($id, $occupate, true) || !empty($nomi['list_' . $id]) || tnp_eventi_newsletter_in_attesa($id)) {
            continue;
        }
        if ((int) $wpdb->get_var('select count(*) from ' . NEWSLETTER_USERS_TABLE . " where list_$id=1") === 0) {
            return $id;
        }
    }
    return 0;
}

/* Modulo */

function tnp_eventi_testo_modulo() {
    $modulo = file_get_contents(__DIR__ . '/modulo.txt');
    $condizioni = (string) get_option('tnp_eventi_url_condizioni', '');
    if ($condizioni === '') {
        $modulo = preg_replace('/<div class="tnp-evento-casella">\s*\[acceptance condizioni\].*?<\/div>\s*/s', '', $modulo);
    }
    $casella = (string) get_option('tnp_eventi_testo_casella', '') ?: 'Voglio ricevere anche la newsletter.';
    return str_replace(
            ['URL_PRIVACY', 'URL_CONDIZIONI', 'Voglio ricevere anche la newsletter.'],
            [esc_url((string) get_option('tnp_eventi_url_privacy', '')), esc_url($condizioni), str_replace('"', '', $casella)],
            $modulo
    );
}

function tnp_eventi_crea_modulo($titolo, $lista) {
    $modulo = WPCF7_ContactForm::get_template(['title' => 'Evento: ' . $titolo]);
    $mail = $modulo->prop('mail');
    $mail['subject'] = 'Nuova prenotazione: ' . $titolo;
    $mail['body'] = "Evento: $titolo\nNome: [your-name] [your-surname]\nEmail: [your-email]";
    $mail['additional_headers'] = 'Reply-To: [your-email]';
    $modulo->set_properties([
        'form' => tnp_eventi_testo_modulo(),
        'mail' => $mail,
        'additional_settings' => 'tnp_lista_evento: ' . (int) $lista,
    ]);
    $modulo->save();
    return $modulo;
}

add_action('wp_enqueue_scripts', function () {
    wp_register_style('tnp-eventi', false);
    wp_enqueue_style('tnp-eventi');
    $colore = sanitize_hex_color((string) get_option('tnp_eventi_colore', '')) ?: '#e59a3a';
    wp_add_inline_style('tnp-eventi', str_replace('#e59a3a', $colore, file_get_contents(__DIR__ . '/stile.css')));
});

/* Testi */

function tnp_eventi_testi_predefiniti($titolo, $data, $luogo) {
    $giorno = wp_date('l j F', $data);
    $ora = wp_date('H:i', $data);
    $dove = $luogo !== '' ? " presso $luogo" : '';
    $pagina = "\n\nTutti i dettagli sono qui: {link_evento}";
    return [
        ['giorni' => -7, 'oggetto' => "Manca una settimana: $titolo", 'riquadro' => true,
            'testo' => "Ciao {name},\n\nti ricordiamo che $giorno alle $ora ti aspettiamo per \"$titolo\"$dove.$pagina\n\nSe non puoi più venire, rispondi a questa email: ci aiuti a organizzarci."],
        ['giorni' => -1, 'oggetto' => "Domani: $titolo", 'riquadro' => true,
            'testo' => "Ciao {name},\n\nci vediamo domani alle $ora per \"$titolo\"$dove.$pagina\n\nSe hai un imprevisto, rispondi a questa email."],
        ['giorni' => 1, 'oggetto' => "Grazie per aver partecipato: $titolo", 'riquadro' => false,
            'testo' => "Ciao {name},\n\ngrazie per aver partecipato a \"$titolo\". Speriamo di rivederti presto."],
    ];
}

function tnp_eventi_html($testo, $evento_id, $riquadro) {
    $html = file_get_contents(__DIR__ . '/promemoria.html');
    $stile = 'margin:0 0 16px;';
    $paragrafi = '';
    $link = (string) get_post_meta($evento_id, '_tnp_url', true);
    foreach (preg_split('/\n\s*\n/', trim($testo)) as $paragrafo) {
        // Senza link, il paragrafo che lo cita non avrebbe senso.
        if ($link === '' && strpos($paragrafo, '{link_evento}') !== false) {
            continue;
        }
        $paragrafo = str_replace('{link_evento}', $link, $paragrafo);
        $paragrafi .= '<p style="' . $stile . '">' . nl2br(make_clickable(esc_html($paragrafo))) . "</p>\n";
    }
    $box = '';
    if ($riquadro) {
        $data = (int) get_post_meta($evento_id, '_tnp_data', true);
        $luogo = (string) get_post_meta($evento_id, '_tnp_luogo', true);
        $box = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;background:#f5f5f5;border-radius:6px;"><tr><td style="padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#333333;">'
                . '<strong>' . esc_html(get_the_title($evento_id)) . '</strong><br>' . esc_html(wp_date('l j F Y, H:i', $data))
                . ($luogo !== '' ? '<br>' . esc_html($luogo) : '') . '</td></tr></table>';
    }
    return str_replace(['{{TESTO}}', '{{RIQUADRO}}'], [$paragrafi, $box], $html);
}

/* Eventi e promemoria */

function tnp_eventi_leggi_data($valore) {
    try {
        return (new DateTimeImmutable((string) $valore, wp_timezone()))->getTimestamp();
    } catch (Exception $errore) {
        return 0;
    }
}

function tnp_eventi_aggiungi_promemoria($evento_id, $oggetto, $testo, $data, $riquadro) {
    $promemoria = tnp_eventi_nuovo_promemoria(
            (int) get_post_meta($evento_id, '_tnp_lista', true), 'evento-' . $evento_id, $oggetto,
            tnp_eventi_html($testo, $evento_id, $riquadro), $data, '',
            ['tnp_testo' => $testo, 'tnp_riquadro' => $riquadro ? 1 : 0]
    );
    return $promemoria;
}

/**
 * @return int|WP_Error L'id dell'evento
 */
function tnp_eventi_crea_evento($titolo, $data, $luogo = '', $url = '') {
    $verifica = tnp_eventi_verifica_completa();
    if (is_wp_error($verifica)) {
        return $verifica;
    }
    if (!class_exists('WPCF7_ContactForm')) {
        return new WP_Error('tnp_eventi_dipendenze', 'Contact Form 7 non è attivo.');
    }
    if ((string) get_option('tnp_eventi_url_privacy', '') === '') {
        return new WP_Error('tnp_eventi_impostazioni', "Indicare l'indirizzo della Privacy Policy in Impostazioni > TNP Eventi.");
    }
    if (trim($titolo) === '') {
        return new WP_Error('tnp_eventi_dati', "Serve il titolo dell'evento.");
    }
    if ($data <= time()) {
        return new WP_Error('tnp_eventi_data', "La data dell'evento deve essere nel futuro.");
    }

    $lista = tnp_eventi_lista_libera();
    if (!$lista) {
        return new WP_Error('tnp_eventi_lista', 'Nessuna lista libera: chiudere un evento passato per liberarne una.');
    }

    $evento_id = wp_insert_post(['post_type' => TNP_EVENTI_POST_TYPE, 'post_status' => 'publish', 'post_title' => $titolo], true);
    if (is_wp_error($evento_id)) {
        return $evento_id;
    }

    $modulo = tnp_eventi_crea_modulo($titolo, $lista);
    tnp_eventi_nomina_lista($lista, 'Evento: ' . $titolo);
    update_post_meta($evento_id, '_tnp_lista', $lista);
    update_post_meta($evento_id, '_tnp_data', $data);
    update_post_meta($evento_id, '_tnp_luogo', $luogo);
    update_post_meta($evento_id, '_tnp_url', $url);
    update_post_meta($evento_id, '_tnp_modulo', $modulo->id());

    $inizio = new DateTimeImmutable('@' . $data);
    foreach (tnp_eventi_testi_predefiniti($titolo, $data, $luogo) as $bozza) {
        $invio = $inizio->setTimezone(wp_timezone())->modify($bozza['giorni'] . ' days')->setTime(10, 0)->getTimestamp();
        if ($invio > time()) {
            tnp_eventi_aggiungi_promemoria($evento_id, $bozza['oggetto'], $bozza['testo'], $invio, $bozza['riquadro']);
        }
    }

    return $evento_id;
}

/**
 * Salva il link dell'evento e lo porta nelle bozze. Le email già approvate restano come sono.
 */
function tnp_eventi_imposta_link($evento_id, $url) {
    update_post_meta($evento_id, '_tnp_url', $url);
    foreach (tnp_eventi_promemoria_evento($evento_id) as $promemoria) {
        if ($promemoria->status === TNP_Email::STATUS_DRAFT && isset($promemoria->options['tnp_testo'])) {
            Newsletter::instance()->save_email([
                'id' => $promemoria->id,
                'message' => tnp_eventi_html($promemoria->options['tnp_testo'], $evento_id, !empty($promemoria->options['tnp_riquadro'])),
            ]);
        }
    }
    return true;
}

function tnp_eventi_promemoria_evento($evento_id) {
    global $wpdb;
    $righe = $wpdb->get_results($wpdb->prepare(
                    'select * from ' . NEWSLETTER_EMAILS_TABLE . ' where options like %s order by send_on asc',
                    '%' . $wpdb->esc_like('"evento-' . (int) $evento_id . '"') . '%'
    ));
    foreach ($righe as $riga) {
        $riga->options = maybe_unserialize($riga->options);
    }
    usort($righe, function ($a, $b) {
        return ($a->options['tnp_data'] ?? $a->send_on) <=> ($b->options['tnp_data'] ?? $b->send_on);
    });
    return $righe;
}

function tnp_eventi_salva_promemoria($id, $evento_id, $oggetto, $testo, $data) {
    $promemoria = tnp_eventi_carica_promemoria($id);
    if (is_wp_error($promemoria)) {
        return $promemoria;
    }
    if ($promemoria->status !== TNP_Email::STATUS_DRAFT) {
        return new WP_Error('tnp_eventi_stato', 'Si può modificare solo una bozza.');
    }
    if (trim($oggetto) === '' || trim($testo) === '') {
        return new WP_Error('tnp_eventi_dati', 'Servono oggetto e testo.');
    }
    if ($data <= time()) {
        return new WP_Error('tnp_eventi_data', 'La data di invio deve essere nel futuro.');
    }
    $opzioni = maybe_unserialize($promemoria->options);
    $opzioni['tnp_testo'] = $testo;
    $opzioni['tnp_data'] = $data;
    Newsletter::instance()->save_email([
        'id' => $promemoria->id,
        'subject' => $oggetto,
        'send_on' => $data,
        'message' => tnp_eventi_html($testo, $evento_id, !empty($opzioni['tnp_riquadro'])),
        'options' => $opzioni,
    ]);
    return true;
}

/**
 * Riporta in bozza un promemoria programmato e non ancora partito.
 */
function tnp_eventi_rimetti_in_bozza($id) {
    $promemoria = tnp_eventi_carica_promemoria($id);
    if (is_wp_error($promemoria)) {
        return $promemoria;
    }
    if ($promemoria->status !== TNP_Email::STATUS_SENDING || (int) $promemoria->sent !== 0 || (int) $promemoria->send_on <= time()) {
        return new WP_Error('tnp_eventi_stato', 'Il promemoria è già partito.');
    }
    Newsletter::instance()->set_email_status($promemoria, TNP_Email::STATUS_DRAFT);
    return true;
}

/**
 * Libera la lista per un altro evento. Il modulo perde il collegamento: se resta su una pagina
 * non deve iscrivere nessuno alla lista, che presto sarà di un altro evento.
 */
function tnp_eventi_chiudi_evento($evento_id) {
    $lista = (int) get_post_meta($evento_id, '_tnp_lista', true);
    $svuotata = tnp_eventi_svuota($lista);
    if (is_wp_error($svuotata)) {
        return $svuotata;
    }
    $modulo = wpcf7_contact_form((int) get_post_meta($evento_id, '_tnp_modulo', true));
    if ($modulo) {
        $modulo->set_properties(['additional_settings' => '']);
        $modulo->save();
    }
    tnp_eventi_nomina_lista($lista, '');
    update_post_meta($evento_id, '_tnp_chiuso', 1);
    return true;
}

/* Azioni dai pulsanti */

add_action('admin_post_tnp_eventi', function () {
    if (!current_user_can(tnp_eventi_permesso())) {
        wp_die('Permesso negato.');
    }
    check_admin_referer('tnp_eventi');

    $fare = sanitize_key($_POST['fare'] ?? '');
    $evento_id = (int) ($_POST['evento'] ?? 0);
    $id = (int) ($_POST['promemoria'] ?? 0);
    $oggetto = sanitize_text_field(wp_unslash($_POST['oggetto'] ?? ''));
    $testo = sanitize_textarea_field(wp_unslash($_POST['testo'] ?? ''));
    $data = tnp_eventi_leggi_data(sanitize_text_field(wp_unslash($_POST['data'] ?? '')));
    $esito = true;
    $fatto = '';

    switch ($fare) {
        case 'crea':
            $esito = tnp_eventi_crea_evento(
                    sanitize_text_field(wp_unslash($_POST['titolo'] ?? '')), $data,
                    sanitize_text_field(wp_unslash($_POST['luogo'] ?? '')), esc_url_raw(wp_unslash($_POST['url'] ?? ''))
            );
            if (!is_wp_error($esito)) {
                $evento_id = $esito;
                $fatto = 'Evento creato. Ora metti il modulo nella pagina e rivedi i promemoria.';
            }
            break;
        case 'link':
            $esito = tnp_eventi_imposta_link($evento_id, esc_url_raw(wp_unslash($_POST['url'] ?? '')));
            $fatto = 'Link salvato: compare nelle email ancora in bozza.';
            break;
        case 'salva':
            $esito = tnp_eventi_salva_promemoria($id, $evento_id, $oggetto, $testo, $data);
            $fatto = 'Bozza salvata.';
            break;
        case 'programma':
            $esito = tnp_eventi_salva_promemoria($id, $evento_id, $oggetto, $testo, $data);
            if (!is_wp_error($esito)) {
                $esito = tnp_eventi_programma($id);
            }
            $fatto = 'Promemoria approvato: partirà alla data indicata.';
            break;
        case 'bozza':
            $esito = tnp_eventi_rimetti_in_bozza($id);
            $fatto = 'Invio annullato: il promemoria è tornato in bozza.';
            break;
        case 'elimina':
            $esito = tnp_eventi_annulla($id);
            $fatto = 'Promemoria eliminato.';
            break;
        case 'aggiungi':
            $esito = tnp_eventi_aggiungi_promemoria($evento_id, 'Nuova email: ' . get_the_title($evento_id), "Ciao {name},\n\n", time() + DAY_IN_SECONDS, true);
            $fatto = 'Bozza aggiunta.';
            break;
        case 'chiudi':
            $esito = tnp_eventi_chiudi_evento($evento_id);
            $fatto = 'Evento chiuso: la lista è di nuovo libera.';
            break;
    }

    set_transient('tnp_eventi_avviso_' . get_current_user_id(), is_wp_error($esito) ? ['error', $esito->get_error_message()] : ['success', $fatto], 60);
    $chiuso = $fare === 'chiudi' && !is_wp_error($esito);
    wp_safe_redirect(admin_url('admin.php?page=tnp-eventi' . ($evento_id && !$chiuso ? '&evento=' . $evento_id : ($fare === 'crea' ? '&nuovo=1' : ''))));
    exit;
});

/* Schermate */

add_action('admin_menu', function () {
    add_menu_page('Eventi', 'Eventi', tnp_eventi_permesso(), 'tnp-eventi', 'tnp_eventi_pagina', 'dashicons-calendar-alt', 26);
});

function tnp_eventi_campi_form($fare, $evento_id = 0, $promemoria_id = 0) {
    wp_nonce_field('tnp_eventi');
    echo '<input type="hidden" name="action" value="tnp_eventi"><input type="hidden" name="fare" value="' . esc_attr($fare) . '">';
    echo '<input type="hidden" name="evento" value="' . (int) $evento_id . '"><input type="hidden" name="promemoria" value="' . (int) $promemoria_id . '">';
}

function tnp_eventi_pulsante($fare, $etichetta, $evento_id, $promemoria_id = 0, $conferma = '') {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;"' . ($conferma ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($conferma)) . ')"' : '') . '>';
    tnp_eventi_campi_form($fare, $evento_id, $promemoria_id);
    submit_button($etichetta, 'secondary', 'submit', false);
    echo '</form>';
}

function tnp_eventi_pagina() {
    if (!current_user_can(tnp_eventi_permesso())) {
        return;
    }
    echo '<div class="wrap">';
    $avviso = get_transient('tnp_eventi_avviso_' . get_current_user_id());
    if ($avviso) {
        delete_transient('tnp_eventi_avviso_' . get_current_user_id());
        echo '<div class="notice notice-' . esc_attr($avviso[0]) . '"><p>' . esc_html($avviso[1]) . '</p></div>';
    }
    $verifica = tnp_eventi_verifica_completa();
    if (is_wp_error($verifica)) {
        echo '<h1>Eventi</h1><p>' . esc_html($verifica->get_error_message()) . '</p></div>';
        return;
    }

    if (!empty($_GET['evento'])) {
        tnp_eventi_schermata_evento((int) $_GET['evento']);
    } elseif (!empty($_GET['nuovo'])) {
        tnp_eventi_schermata_nuovo();
    } else {
        tnp_eventi_schermata_elenco();
    }
    echo '</div>';
}

function tnp_eventi_schermata_elenco() {
    echo '<h1 class="wp-heading-inline">Eventi</h1> <a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=tnp-eventi&nuovo=1')) . '">Nuovo evento</a><hr class="wp-header-end">';
    $eventi = tnp_eventi_eventi();
    if (!$eventi) {
        echo '<p>Non ci sono ancora eventi. Crea il primo con "Nuovo evento".</p>';
        return;
    }
    global $wpdb;
    echo '<table class="widefat striped" style="margin-top:16px;"><thead><tr><th>Evento</th><th>Data</th><th>Prenotati</th><th>Promemoria</th><th>Stato</th></tr></thead><tbody>';
    foreach ($eventi as $evento) {
        $lista = (int) get_post_meta($evento->ID, '_tnp_lista', true);
        $chiuso = get_post_meta($evento->ID, '_tnp_chiuso', true);
        $promemoria = tnp_eventi_promemoria_evento($evento->ID);
        $bozze = count(array_filter($promemoria, function ($p) {
                    return $p->status === TNP_Email::STATUS_DRAFT;
                }));
        $url = admin_url('admin.php?page=tnp-eventi&evento=' . $evento->ID);
        echo '<tr><td><strong><a href="' . esc_url($url) . '">' . esc_html($evento->post_title) . '</a></strong></td>';
        echo '<td>' . esc_html(wp_date('j F Y, H:i', (int) get_post_meta($evento->ID, '_tnp_data', true))) . '</td>';
        echo '<td>' . ($chiuso ? '-' : (int) $wpdb->get_var('select count(*) from ' . NEWSLETTER_USERS_TABLE . " where status='C' and list_$lista=1")) . '</td>';
        echo '<td>' . count($promemoria) . ($bozze ? ' (' . $bozze . ' da approvare)' : '') . '</td>';
        echo '<td>' . ($chiuso ? 'Chiuso' : 'Aperto') . '</td></tr>';
    }
    echo '</tbody></table>';
}

function tnp_eventi_schermata_nuovo() {
    echo '<h1>Nuovo evento</h1><p>Il plugin prepara da solo la lista degli iscritti, il modulo di prenotazione e tre email da rivedere: una settimana prima, il giorno prima e il giorno dopo. Nulla parte finché non lo approvi.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    tnp_eventi_campi_form('crea');
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th><label for="titolo">Titolo</label></th><td><input required class="regular-text" id="titolo" name="titolo" type="text"></td></tr>';
    echo '<tr><th><label for="data">Data e ora</label></th><td><input required id="data" name="data" type="datetime-local"></td></tr>';
    echo '<tr><th><label for="luogo">Luogo</label></th><td><input class="regular-text" id="luogo" name="luogo" type="text"></td></tr>';
    echo '<tr><th><label for="url">Link alla pagina dell\'evento</label></th><td><input class="regular-text" id="url" name="url" type="url"><p class="description">Compare nelle email. Se la pagina non esiste ancora, lascia vuoto: potrai aggiungerlo dopo.</p></td></tr>';
    echo '</table>';
    submit_button('Crea evento');
    echo '</form>';
}

function tnp_eventi_richiesta_claude($evento_id) {
    $data = (int) get_post_meta($evento_id, '_tnp_data', true);
    return "Scrivi le email per questo evento, in italiano, in un tono cordiale e semplice.\n\n"
            . 'Evento: ' . get_the_title($evento_id) . "\n"
            . 'Quando: ' . wp_date('l j F Y, H:i', $data) . "\n"
            . 'Dove: ' . get_post_meta($evento_id, '_tnp_luogo', true) . "\n"
            . "Altre informazioni: [AGGIUNGI QUI ciò che conta: ospiti, costo, cosa portare]\n\n"
            . "Mi servono tre email: una settimana prima, il giorno prima e il giorno dopo (ringraziamento).\n"
            . "Per ciascuna dammi oggetto e testo, solo testo semplice, breve. Inizia il testo con \"Ciao {name},\" scritto esattamente così: il sistema lo sostituisce con il nome. Per il link alla pagina dell'evento scrivi {link_evento}, esattamente così e in un paragrafo a sé. "
            . "Non inventare dettagli che non ti ho dato: se manca qualcosa di importante, chiedimelo prima.";
}

function tnp_eventi_schermata_evento($evento_id) {
    $evento = get_post($evento_id);
    if (!$evento || $evento->post_type !== TNP_EVENTI_POST_TYPE) {
        echo '<h1>Eventi</h1><p>Evento non trovato.</p>';
        return;
    }
    global $wpdb;
    $lista = (int) get_post_meta($evento_id, '_tnp_lista', true);
    $chiuso = get_post_meta($evento_id, '_tnp_chiuso', true);
    $modulo = wpcf7_contact_form((int) get_post_meta($evento_id, '_tnp_modulo', true));
    $esclusi = get_option(TNP_EVENTI_OPZIONE_ESCLUSI, [])[$lista] ?? [];

    echo '<p><a href="' . esc_url(admin_url('admin.php?page=tnp-eventi')) . '">&larr; Tutti gli eventi</a></p>';
    echo '<h1>' . esc_html($evento->post_title) . '</h1>';
    echo '<p>' . esc_html(wp_date('l j F Y, H:i', (int) get_post_meta($evento_id, '_tnp_data', true))) . ' ' . esc_html((string) get_post_meta($evento_id, '_tnp_luogo', true)) . '</p>';

    if ($chiuso) {
        echo '<p><strong>Evento chiuso.</strong> Gli iscritti sono stati tolti dalla lista.</p>';
    } else {
        $prenotati = (int) $wpdb->get_var('select count(*) from ' . NEWSLETTER_USERS_TABLE . " where status='C' and list_$lista=1");
        echo '<p><strong>Prenotati:</strong> ' . $prenotati . '</p>';
        echo '<h2>1. Metti il modulo nella pagina dell\'evento</h2>';
        if ($modulo) {
            echo '<p>Copia questo codice e incollalo nella pagina:</p><input type="text" readonly class="large-text code" onclick="this.select()" value="' . esc_attr($modulo->shortcode()) . '">';
        }
        $link = (string) get_post_meta($evento_id, '_tnp_url', true);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        tnp_eventi_campi_form('link', $evento_id);
        echo '<p><label for="tnp-link">Poi scrivi qui il link alla pagina, così compare nelle email:</label><br><input class="large-text" id="tnp-link" type="url" name="url" value="' . esc_attr($link) . '" placeholder="https://"></p>';
        submit_button('Salva link', 'secondary', 'submit', false);
        echo '</form>';
    }

    if ($esclusi) {
        echo '<h2>Persone da avvisare a mano</h2><p>Si sono prenotate, ma non riceveranno i promemoria: per esempio si erano disiscritte in passato.</p><ul>';
        foreach ($esclusi as $escluso) {
            echo '<li>' . esc_html($escluso['email']) . '</li>';
        }
        echo '</ul>';
    }

    echo '<h2>' . ($chiuso ? 'Promemoria' : '2. Rivedi e approva i promemoria') . '</h2>';
    if (!$chiuso) {
        echo '<details style="margin-bottom:16px;"><summary>Vuoi farti aiutare da Claude con i testi?</summary><p>Copia questa richiesta in Claude, completala, poi incolla qui oggetto e testo di ogni email.</p><textarea readonly class="large-text" rows="9" onclick="this.select()">' . esc_textarea(tnp_eventi_richiesta_claude($evento_id)) . '</textarea></details>';
    }

    $etichette = [TNP_Email::STATUS_DRAFT => 'Bozza: non partirà finché non la approvi', TNP_Email::STATUS_SENDING => 'Approvata', TNP_Email::STATUS_SENT => 'Inviata', TNP_Email::STATUS_PAUSED => 'In pausa', TNP_Email::STATUS_ERROR => 'Errore di invio'];
    foreach (tnp_eventi_promemoria_evento($evento_id) as $p) {
        $data = (int) ($p->options['tnp_data'] ?? $p->send_on);
        echo '<div class="card" style="max-width:800px;margin-bottom:16px;">';
        echo '<p><strong>' . esc_html($etichette[$p->status] ?? $p->status) . '</strong> &middot; ' . esc_html(wp_date('l j F Y, H:i', $data)) . '</p>';
        if ($p->status === TNP_Email::STATUS_DRAFT) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            tnp_eventi_campi_form('salva', $evento_id, $p->id);
            echo '<p><label>Invio<br><input type="datetime-local" name="data" value="' . esc_attr(wp_date('Y-m-d\TH:i', $data)) . '"></label></p>';
            echo '<p><label>Oggetto<br><input class="large-text" type="text" name="oggetto" value="' . esc_attr($p->subject) . '"></label></p>';
            echo '<p><label>Testo<br><textarea class="large-text" rows="8" name="testo">' . esc_textarea($p->options['tnp_testo'] ?? '') . '</textarea></label></p>';
            echo '<p class="description">{name} diventa il nome di chi riceve l\'email, {link_evento} il link alla pagina dell\'evento: senza link, il paragrafo che lo contiene non compare. Destinatari: tutti i prenotati al momento dell\'invio.</p>';
            echo '<p><button class="button" name="fare" value="salva">Salva bozza</button> <button class="button button-primary" name="fare" value="programma" onclick="return confirm(\'Approvi questa email? Partirà alla data indicata.\')">Approva e programma</button></p></form>';
            tnp_eventi_pulsante('elimina', 'Elimina bozza', $evento_id, $p->id, 'Eliminare questa bozza?');
        } else {
            echo '<p><strong>' . esc_html($p->subject) . '</strong></p><div style="white-space:pre-wrap;">' . esc_html($p->options['tnp_testo'] ?? '') . '</div>';
            if ($p->status === TNP_Email::STATUS_SENDING && (int) $p->sent === 0 && (int) $p->send_on > time()) {
                echo '<p>';
                tnp_eventi_pulsante('bozza', 'Annulla l\'invio e torna in bozza', $evento_id, $p->id);
                echo '</p>';
            }
        }
        echo '</div>';
    }

    if (!$chiuso) {
        tnp_eventi_pulsante('aggiungi', 'Aggiungi un\'altra email', $evento_id);
        echo '<h2>3. Dopo l\'evento</h2><p>Quando tutte le email sono partite, chiudi l\'evento: gli iscritti escono dalla lista, che torna disponibile, e il modulo smette di iscrivere. Chi ha chiesto la newsletter continua a riceverla.</p>';
        tnp_eventi_pulsante('chiudi', 'Chiudi evento', $evento_id, 0, 'Chiudere l\'evento? Non si può annullare.');
    }
}
