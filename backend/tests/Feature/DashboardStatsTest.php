<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endpoint `GET /api/v1/dashboard/stats` — filtre de période **global**
 * (`?year` / `?month` / `?week` / `?day`, cartes + graphique + réservations +
 * « Meilleurs employés ») : appels = notes porteuses d'un `call_log_id`
 * créées par le bouton « Appel » RingCentral, OUI = notes d'issue `YES`,
 * échec = appels − OUI. Le graphique rend un point par mois (année seule),
 * par jour du mois (mois choisi) ou par jour de la semaine (1 = jours 1–7),
 * et un point unique pour un jour seul (`?day`).
 * Exception : `stats.blacklisted_clients` = instantané des clients
 * actuellement blacklistés.
 *
 * L'`OPERATOR` a un tableau de bord **simple**, hors période : uniquement ses
 * saisies de numéro de téléphone (notes `MISE_A_JOUR` dont le diff parle de
 * « Téléphone ») et ce qui reste dans sa liste — voir
 * `test_operator_dashboard_*`.
 */
class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCommercial(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    private function makeNote(Client $client, User $sender, string $type): Note
    {
        return Note::create([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => $type,
        ]);
    }

    /**
     * Note « appel » telle que la crée le bouton « Appel » (RingCentral) :
     * note `TYPE_NOTE` porteuse d'un `call_log_id`, émetteur `SYSTEM`, le
     * journal d'appel rattaché à l'employé qui a appelé (nullable pour les
     * chiffres globaux du gestionnaire).
     */
    private function makeCallNote(Client $client, ?Employee $employee = null): Note
    {
        $call = CallLog::create([
            'employee_id' => $employee?->id,
            'client_id' => $client->id,
            'to_number' => $client->phone,
            'started_at' => now(),
        ]);

        return Note::create([
            'client_id' => $client->id,
            'call_log_id' => $call->id,
            'sender_id' => Note::SENDER_SYSTEM,
            'type' => Note::TYPE_NOTE,
        ]);
    }

    /**
     * Note de journalisation d'une édition humaine, telle que la produit
     * `ClientUpdateNote::log()` : diff champ par champ, avec les guillemets
     * français qui servent de repère au comptage des saisies de téléphone.
     */
    private function makeUpdateNote(Client $client, User $sender, string $field, string $old, string $new): Note
    {
        return Note::create([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => Note::TYPE_MISE_A_JOUR,
            'description' => "Fiche mise à jour — {$field} : « {$old} » → « {$new} ».",
        ]);
    }

    private function makeEmployee(User $user): Employee
    {
        return Employee::create([
            'user_id' => $user->id,
            'enterprise_id' => Enterprise::create(['name' => 'Entreprise A'])->id,
            'first_name' => $user->first_name ?? 'Emp',
            'last_name' => $user->last_name ?? 'LOYÉ',
        ]);
    }

    public function test_admin_cards_count_call_notes_and_oui_for_the_current_year(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        // figé : `Carbon::today()` rendrait la date **gelée** par le
        // `travelTo` précédent, pas le jour réel.
        $today = Carbon::today();

        $this->travelTo($today->copy()->setTime(10, 0));
        // Deux appels cette année (bouton « Appel ») et un OUI ; les autres
        // issues (NON / BLACKLISTED) ne pilotent plus les cartes.
        $this->makeCallNote($client);
        $this->makeCallNote($client);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $commercial, Note::TYPE_NO);
        // Client **actuellement** blacklisté : instantané, hors période.
        $this->makeClient(['name' => 'Banni', 'is_blacklisted' => true]);

        // Hors année : un appel et un OUI de l'année précédente sont exclus.
        $this->travelTo($today->copy()->subYear()->setTime(10, 0));
        $this->makeCallNote($client);
        $this->makeNote($client, $commercial, Note::TYPE_YES);

        $this->travelTo($today->copy()->setTime(10, 0));
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // Filtre par défaut : année courante, mois et semaine « tous ».
        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.stats.period.yes', 1)
            // Échec = appels − OUI.
            ->assertJsonPath('data.stats.period.failed', 1)
            ->assertJsonPath('data.stats.blacklisted_clients', 1)
            ->assertJsonPath('data.chart.month', null)
            ->assertJsonPath('data.chart.week', null)
            // Chiffres de contexte (cartes non « appel ») toujours servis.
            ->assertJsonPath('data.stats.enterprises', 0)
            ->assertJsonPath('data.stats.commercials', 1)
            ->assertJsonPath('data.stats.prospects', 2);
    }

    /**
     * Filtre « Source » du tableau de bord gestionnaire (`?source=`) : le même
     * onglet que la Grande liste admin (§13) — il borne **tous** les chiffres
     * liés aux clients (prospects, blacklist, appels / OUI / échecs,
     * réservations, « Meilleurs employés »), jamais les compteurs
     * structurels (entreprises / commerciaux). Il est **ignoré** côté
     * commercial, dont le périmètre est décidé par le serveur.
     */
    public function test_admin_source_filter_scopes_every_client_derived_card(): void
    {
        $commercial = $this->makeCommercial();
        $employee = $this->makeEmployee($commercial);

        $anglais = $this->makeClient(['source' => 'Anglais']);
        $francais = $this->makeClient(['source' => 'Francais', 'name' => 'Maçonnerie du Nord']);

        $this->travelTo(Carbon::today()->setTime(10, 0));

        // Anglais : 2 appels, 1 OUI, 1 réservation, 1 blacklisté.
        $this->makeCallNote($anglais, $employee);
        $this->makeCallNote($anglais, $employee);
        $this->makeNote($anglais, $commercial, Note::TYPE_YES);
        $this->makeClient(['source' => 'Anglais', 'name' => 'Banni EN', 'is_blacklisted' => true]);
        Reservation::create([
            'client_id' => $anglais->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        // Français : 1 appel et 1 réservation hors origine sélectionnée.
        $this->makeCallNote($francais, $employee);
        Reservation::create([
            'client_id' => $francais->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/dashboard/stats?source=Anglais')
            ->assertOk()
            ->assertJsonPath('data.source', 'Anglais')
            ->assertJsonPath('data.stats.prospects', 2)
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.stats.period.yes', 1)
            ->assertJsonPath('data.stats.period.failed', 1)
            ->assertJsonPath('data.stats.blacklisted_clients', 1)
            ->assertJsonPath('data.stats.reservations', 1)
            // Compteurs structurels : inchangés sous filtre source.
            ->assertJsonPath('data.stats.commercials', 1)
            ->assertJsonPath('data.top_commercials.0.calls', 2)
            ->assertJsonPath('data.top_commercials.0.oui', 1)
            ->assertJsonPath('data.top_commercials.0.reservations', 1);

        // « Toutes » (paramètre absent) : tout est compté.
        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.source', null)
            ->assertJsonPath('data.stats.prospects', 3)
            ->assertJsonPath('data.stats.period.calls', 3)
            ->assertJsonPath('data.stats.reservations', 2)
            ->assertJsonPath('data.top_commercials.0.calls', 3);

        // Commercial : `source` ne déplace **rien** — sa liste est verrouillée
        // sur la source de son entreprise côté serveur.
        Sanctum::actingAs($commercial);
        $this->getJson('/api/v1/dashboard/stats?source=Anglais')
            ->assertOk()
            ->assertJsonMissingPath('data.source')
            ->assertJsonPath('data.stats.period.calls', 3);
    }

    public function test_commercial_cards_are_scoped_to_own_calls(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();
        $client = $this->makeClient();
        $mine = $this->makeEmployee($commercial);
        $his = $this->makeEmployee($other);

        $this->travelTo(Carbon::today()->setTime(10, 0));
        // 2 appels au mien, 1 OUI ; l'appel et le NON de l'autre sont exclus
        // (la note d'appel est émise par `SYSTEM` : le périmètre passe par
        // l'employé du journal d'appel).
        $this->makeCallNote($client, $mine);
        $this->makeCallNote($client, $mine);
        $this->makeCallNote($client, $his);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $other, Note::TYPE_NO);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.role', 'COMERCIAL')
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.stats.period.yes', 1)
            ->assertJsonPath('data.stats.period.failed', 1)
            // Blacklist = instantané global, identique pour le commercial.
            ->assertJsonPath('data.stats.blacklisted_clients', 0)
            ->assertJsonPath('data.top_commercials', []);
    }

    /**
     * Cartes « travail en cours » du commercial — `prospects` / `libre` /
     * `double` / `info` / `bv` / `rappel`. Invariant « carte = lignes rendues »
     * (§9) : les quatre premiers comptent **les lignes de la Grande liste**
     * (`GET clients` : source d'entreprise, téléphone, hors blacklist, non
     * tenu — plus les « Double » / « Info » que l'employé **détient**) ;
     * `bv` / `rappel`, les rappels `pending` des pages dédiées. Tous
     * instantanés, non filtrés par la période.
     */
    public function test_commercial_work_cards_expose_list_counts_bv_and_rappel(): void
    {
        // Le privilège (§7.1) ne porte **que** sur la libération de la liste :
        // un commercial **sans** `has_permission` compte bien le « Double »
        // qu'il détient dans ses cartes (carte = lignes rendues).
        $commercial = $this->makeCommercial();
        $employee = $this->makeEmployee($commercial);

        $enterprise = Enterprise::create(['name' => 'Ent. source', 'source' => 'Francais']);
        $employee->update(['enterprise_id' => $enterprise->id]);

        // Grande liste : 2 disponibles de la source ; hors source, sans
        // téléphone et blacklistés exclus (mêmes règles que `GET clients`).
        $this->makeClient(['source' => 'Francais']);
        $this->makeClient(['source' => 'Francais', 'name' => 'Libre 2']);
        $this->makeClient(['source' => 'Affaires']);
        $this->makeClient(['source' => 'Francais', 'phone' => null]);
        $this->makeClient(['source' => 'Francais', 'is_blacklisted' => true]);

        // Client tenu en BV : sort de « Libre » (réservation active) et
        // compte dans `bv` ; son rappel terminé ne compte pas.
        $bv = $this->makeClient(['source' => 'Francais', 'name' => 'Tenu BV', 'status' => Client::STATUS_RESERVED]);
        $bvReservation = Reservation::create([
            'client_id' => $bv->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_BV_VOICEMAIL,
        ]);
        Rappel::create([
            'client_id' => $bv->id,
            'comercial_id' => $commercial->id,
            'reservation_id' => $bvReservation->id,
            'reminder_date' => now(),
        ]);
        Rappel::create([
            'client_id' => $bv->id,
            'comercial_id' => $commercial->id,
            'reservation_id' => $bvReservation->id,
            'reminder_date' => now(),
            'done_at' => now(),
        ]);

        // Client tenu « À rappeler ».
        $recall = $this->makeClient(['source' => 'Francais', 'name' => 'Tenu rappel', 'status' => Client::STATUS_RESERVED]);
        $recallReservation = Reservation::create([
            'client_id' => $recall->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_CALL_BACK,
        ]);
        Rappel::create([
            'client_id' => $recall->id,
            'comercial_id' => $commercial->id,
            'reservation_id' => $recallReservation->id,
            'reminder_date' => now(),
        ]);

        // Client **détenu** en « Double » : il compte dans « Tous » et dans
        // « Double », jamais dans « Libre » (réservation active).
        $double = $this->makeClient(['source' => 'Francais', 'name' => 'Tenu double', 'status' => Client::STATUS_RESERVED]);
        Reservation::create([
            'client_id' => $double->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_DOUBLE,
        ]);
        $double->update(['status' => Client::STATUS_DOUBLE]);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            // « Tous » = les 2 libres + le « Double » détenu.
            ->assertJsonPath('data.stats.prospects', 3)
            ->assertJsonPath('data.stats.libre', 2)
            ->assertJsonPath('data.stats.double', 1)
            ->assertJsonPath('data.stats.info', 0)
            ->assertJsonPath('data.stats.bv', 1)
            ->assertJsonPath('data.stats.rappel', 1);
    }

    /**
     * Les cartes de liste du commercial (`prospects` / `libre` / `bv` …) **réagissent
     * au filtre de période** : elles comptent les clients « actifs » de la fenêtre
     * (fiche créée, réservation posée ou trace d'appel émise pendant la période)
     * et les rappels `pending` dont la **réservation** a été créée sur la période.
     * Filtre par défaut = année courante : les fiches d'une année passée sortent
     * des compteurs.
     */
    public function test_commercial_list_cards_follow_the_period_filter(): void
    {
        $commercial = $this->makeCommercial(['has_permission' => true]);
        $employee = $this->makeEmployee($commercial);
        $enterprise = Enterprise::create(['name' => 'Ent. période', 'source' => 'Francais']);
        $employee->update(['enterprise_id' => $enterprise->id]);

        // Fenêtre courante : un libre créé à l'instant + un rappel BV dont la
        // réservation est créée à l'instant.
        $this->makeClient(['source' => 'Francais', 'name' => 'Libre cette année']);
        $bv = $this->makeClient(['source' => 'Francais', 'name' => 'BV cette année', 'status' => Client::STATUS_RESERVED]);
        $bvReservation = Reservation::create([
            'client_id' => $bv->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_BV_VOICEMAIL,
        ]);
        Rappel::create([
            'client_id' => $bv->id,
            'comercial_id' => $commercial->id,
            'reservation_id' => $bvReservation->id,
            'reminder_date' => now(),
        ]);

        // Fenêtre 2020 : un libre créé le 3 février, un BV créé le 10 février
        // (réservation + rappel du 10) — hors de l'année courante.
        $this->travelTo(Carbon::create(2020, 2, 3, 10, 0));
        $this->makeClient(['source' => 'Francais', 'name' => 'Libre 2020']);
        $this->travelTo(Carbon::create(2020, 2, 10, 10, 0));
        $oldBv = $this->makeClient(['source' => 'Francais', 'name' => 'BV 2020', 'status' => Client::STATUS_RESERVED]);
        $oldReservation = Reservation::create([
            'client_id' => $oldBv->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_BV_VOICEMAIL,
        ]);
        Rappel::create([
            'client_id' => $oldBv->id,
            'comercial_id' => $commercial->id,
            'reservation_id' => $oldReservation->id,
            'reminder_date' => now(),
        ]);
        $this->travelBack();

        Sanctum::actingAs($commercial);

        // Défaut = année courante : les fiches de 2020 disparaissent des compteurs.
        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.stats.prospects', 1)
            ->assertJsonPath('data.stats.libre', 1)
            ->assertJsonPath('data.stats.bv', 1);

        // Année 2020 : le libre du 3 février et le BV du 10 (tenu, hors liste « Tous »
        // comme sur la liste réelle) ; les deux BV (2020 et courant) suivent leur
        // réservation — seul celui de 2020 reste sous `?year=2020`.
        $this->getJson('/api/v1/dashboard/stats?year=2020')
            ->assertOk()
            ->assertJsonPath('data.stats.prospects', 1)
            ->assertJsonPath('data.stats.libre', 1)
            ->assertJsonPath('data.stats.bv', 1);

        // Semaine 1 de février 2020 (jours 1–7) : le libre créé le 3 est actif,
        // la réservation BV du 10 sort de la fenêtre → `bv` = 0.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=1')
            ->assertOk()
            ->assertJsonPath('data.stats.prospects', 1)
            ->assertJsonPath('data.stats.libre', 1)
            ->assertJsonPath('data.stats.bv', 0);

        // Jour 3 février 2020 seul : uniquement le libre créé ce jour.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&day=3')
            ->assertOk()
            ->assertJsonPath('data.stats.prospects', 1)
            ->assertJsonPath('data.stats.libre', 1)
            ->assertJsonPath('data.stats.bv', 0);

        // Jour 11 février 2020 : `day` borne sur le seul jour 11 — aucune
        // activité ce jour-là (le BV a été créé le 10), tous les compteurs tombent à 0.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&day=11')
            ->assertOk()
            ->assertJsonPath('data.stats.prospects', 0)
            ->assertJsonPath('data.stats.libre', 0)
            ->assertJsonPath('data.stats.bv', 0);
    }

    public function test_month_and_week_narrow_every_card(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        $employee = $this->makeEmployee($commercial);

        // Février 2020 (29 jours, bissextile) : dates fixes et passées.
        $this->travelTo(Carbon::create(2020, 1, 15, 10, 0));   // hors mois
        $this->makeCallNote($client, $employee);
        $this->travelTo(Carbon::create(2020, 2, 3, 10, 0));    // semaine 1 (jours 1–7)
        $this->makeCallNote($client, $employee);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->travelTo(Carbon::create(2020, 2, 10, 10, 0));   // semaine 2 (jours 8–14)
        $this->makeCallNote($client, $employee);

        Sanctum::actingAs($commercial);

        // Année seule : les 3 appels de 2020, mois « tous ».
        $this->getJson('/api/v1/dashboard/stats?year=2020')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 3)
            ->assertJsonPath('data.stats.period.yes', 1);

        // Février : 2 appels, sans semaine.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.chart.month', 2)
            ->assertJsonPath('data.chart.week', null);

        // Semaine 1 seule : 1 appel, 1 OUI, 0 échec.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=1')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 1)
            ->assertJsonPath('data.stats.period.yes', 1)
            ->assertJsonPath('data.stats.period.failed', 0)
            ->assertJsonPath('data.chart.week', 1);

        // Semaine hors plage (février 2020 = 5 semaines ; `?week=9` ignoré) :
        // on retrouve le mois entier — la cascade de remise à « toutes » est
        // côté UI, le serveur ne fait pas tomber la requête.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=9')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.chart.week', null);

        // `week` sans `month` : ignoré, l'année entière reste filtrante.
        $this->getJson('/api/v1/dashboard/stats?year=2020&week=1')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 3);
    }

    /**
     * Raccourci « Ce jour » (`?day=`) : le plus fin des réglages borne la
     * période sur **un seul jour** (`[00:00, minuit suivant)`), un seul point de
     * graphique, et éclipse la semaine. Hors mois ou hors plage il vaut « tous
     * les jours » — la cascade de remise à « tous » est côté UI, le serveur ne
     * fait pas tomber la requête.
     */
    public function test_day_filter_bounds_the_period_to_a_single_day(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        $employee = $this->makeEmployee($commercial);

        // Février 2020 : jour 3 = 2 appels dont 1 OUI, jour 4 = 1 appel juste
        // après minuit (il ne doit PAS être compté dans le jour 3), jour 10 =
        // 1 appel de plus pour le mois entier.
        $this->travelTo(Carbon::create(2020, 2, 3, 8, 30));
        $this->makeCallNote($client, $employee);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->travelTo(Carbon::create(2020, 2, 3, 23, 30));
        $this->makeCallNote($client, $employee);
        $this->travelTo(Carbon::create(2020, 2, 4, 0, 30));
        $this->makeCallNote($client, $employee);
        $this->travelTo(Carbon::create(2020, 2, 10, 10, 0));
        $this->makeCallNote($client, $employee);

        Sanctum::actingAs($commercial);

        // Jour 3 : ses 2 appels et son OUI, un seul point de graphique.
        $points = $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&day=3')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.stats.period.yes', 1)
            ->assertJsonPath('data.stats.period.failed', 1)
            ->assertJsonPath('data.chart.day', 3)
            ->json('data.calls_by_day');

        $this->assertCount(1, $points);
        $this->assertSame('2020-02-03', $points[0]['date']);
        $this->assertSame(2, $points[0]['total']);
        $this->assertSame(1, $points[0]['oui']);

        // Le jour prime sur la semaine : la méta echoée ne montre que lui.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=2&day=3')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.chart.week', null)
            ->assertJsonPath('data.chart.day', 3);

        // Jour hors plage (février 2020 n'a que 29 jours) : le mois entier revient.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&day=30')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 4)
            ->assertJsonPath('data.chart.day', null);

        // `day` sans `month` : ignoré, l'année entière reste filtrante.
        $this->getJson('/api/v1/dashboard/stats?year=2020&day=3')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 4)
            ->assertJsonPath('data.chart.day', null);
    }

    public function test_chart_is_monthly_for_a_year_and_daily_for_a_month_or_week(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        $employee = $this->makeEmployee($commercial);

        $this->travelTo(Carbon::create(2020, 2, 10, 9, 0));
        $this->makeCallNote($client, $employee);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->travelTo(Carbon::create(2020, 2, 11, 9, 0));
        $this->makeCallNote($client, $employee);

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // Année seule : 12 points mensuels, février = 2 appels / 1 OUI ;
        // « Meilleurs employés » suit la même période.
        $year = $this->getJson('/api/v1/dashboard/stats?year=2020')->assertOk();
        $points = $year->json('data.calls_by_day');

        $this->assertCount(12, $points);
        $this->assertSame('2020-01', $points[0]['date']);
        $this->assertSame(0, $points[0]['total']);
        $this->assertSame('2020-02', $points[1]['date']);
        $this->assertSame(2, $points[1]['total']);
        $this->assertSame(1, $points[1]['oui']);
        $this->assertSame(2, $year->json('data.top_commercials.0.calls'));
        $this->assertSame(1, $year->json('data.top_commercials.0.oui'));

        // Mois choisi : un point par jour (29 en 2020, bissextile).
        $days = $this->getJson('/api/v1/dashboard/stats?year=2020&month=2')
            ->assertOk()
            ->json('data.calls_by_day');

        $this->assertCount(29, $days);
        $this->assertSame('2020-02-01', $days[0]['date']);
        $this->assertSame('2020-02-10', $days[9]['date']);
        $this->assertSame(1, $days[9]['total']);
        $this->assertSame(1, $days[9]['oui']);
        $this->assertSame(2, collect($days)->sum('total'));

        // Semaine 2 (jours 8–14) : 7 points du 8 au 14, les 2 appels dedans.
        $week = $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=2')
            ->assertOk()
            ->json('data.calls_by_day');

        $this->assertCount(7, $week);
        $this->assertSame('2020-02-08', $week[0]['date']);
        $this->assertSame('2020-02-14', $week[6]['date']);
        $this->assertSame(2, collect($week)->sum('total'));

        // Dernière semaine partielle (jours 29–29) : 1 point.
        $last = $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=5')
            ->assertOk()
            ->json('data.calls_by_day');

        $this->assertCount(1, $last);
        $this->assertSame('2020-02-29', $last[0]['date']);
    }

    public function test_year_selection_stays_in_the_years_list_even_without_data(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // Aucune donnée : le sélecteur doit quand même rendre l'année
        // courante et l'année choisie (ici 2019, avant l'année courante).
        $current = (int) Carbon::today()->format('Y');
        $response = $this->getJson('/api/v1/dashboard/stats?year=2019')->assertOk();
        $years = $response->json('data.chart.years');

        $this->assertContains($current, $years);
        $this->assertContains(2019, $years);
        $response->assertJsonPath('data.chart.year', 2019);
    }

    // ── Tableau de bord **simple** de l'`OPERATOR` ─────────────────────────

    /**
     * Fenêtres **roulantes** (`?range=`) : `last3days` = les 3 jours calendaires
     * ancrés sur aujourd'hui (granularité journalière), `last6months` = les 6
     * mois calendaires entiers jusqu'au mois courant (granularité mensuelle).
     * Elles **priment** sur la cascade Année / Mois / Semaine / Jour et bornent
     * les cartes comme le graphique — un réglage sans équivalent calendaire.
     */
    public function test_rolling_range_presets_bound_cards_and_chart(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $client = $this->makeClient();

        // « Aujourd'hui » figé au 15 février 2020 : `Carbon::today()` (et donc
        // les bornes serveur) suit cette date tant que le voyage temporel tient.
        $today = Carbon::create(2020, 2, 15, 12, 0);

        // Fenêtre de 3 jours = {13, 14, 15} ; le 12 doit en être exclu.
        $this->travelTo($today->copy()->setTime(9, 0));
        $this->makeCallNote($client);
        $this->travelTo($today->copy()->subDay()->setTime(9, 0));
        $this->makeCallNote($client);
        $this->travelTo($today->copy()->subDays(2)->setTime(9, 0));
        $this->makeCallNote($client);
        $this->travelTo($today->copy()->subDays(3)->setTime(9, 0));
        $this->makeCallNote($client);

        $this->travelTo($today);
        Sanctum::actingAs($admin);

        // Derniers 3 jours : 3 appels, 3 points journaliers (13, 14, 15).
        $three = $this->getJson('/api/v1/dashboard/stats?range=last3days')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 3)
            ->json('data.calls_by_day');
        $this->assertCount(3, $three);
        $this->assertSame('2020-02-13', $three[0]['date']);
        $this->assertSame('2020-02-15', $three[2]['date']);

        // Derniers 6 mois : les 4 appels (tous en février), un point par mois
        // de sept. 2019 à févr. 2020 ; le paramètre prime sur un `year` posé.
        $six = $this->getJson('/api/v1/dashboard/stats?range=last6months&year=1999')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 4)
            ->json('data.calls_by_day');
        $this->assertCount(6, $six);
        $this->assertSame('2019-09', $six[0]['date']);
        $this->assertSame('2020-02', $six[5]['date']);

        $this->travelBack();
    }

    /**
     * Tuiles « saisies de téléphone » : une note `MISE_A_JOUR` dont le diff
     * mentionne le champ Téléphone ne compte **que** si elle est de lui, et les
     * trois fenêtres (aujourd'hui / 7 jours / 30 jours) sont des jours
     * calendaires incluant aujourd'hui — une édition de la veille à 23 h 59
     * sort de « Aujourd'hui » mais reste dans la semaine.
     *
     * Les autres champs édités (nom, adresse…) ne sont pas des saisies de
     * numéro et sont exclus.
     */
    public function test_operator_dashboard_counts_his_phone_updates_across_fixed_windows(): void
    {
        $operator = User::factory()->create(['role' => 'OPERATOR', 'status' => 'ACTIVE']);
        $colleague = User::factory()->create(['role' => 'OPERATOR', 'status' => 'ACTIVE']);
        $client = $this->makeClient();

        $today = Carbon::today();

        $this->travelTo($today->copy()->setTime(9, 0));
        $this->makeUpdateNote($client, $operator, 'Téléphone', '(vide)', '514-555-0101');
        $this->makeUpdateNote($client, $operator, 'Téléphone', '514-555-0101', '514-555-0102');
        // Édition d'un autre champ : ce n'est pas une saisie de numéro.
        $this->makeUpdateNote($client, $operator, 'Nom', 'ACME', 'ACME Renové');
        // Édition d'un homologue : le tableau de bord ne compte **que** les siennes.
        $this->makeUpdateNote($client, $colleague, 'Téléphone', '(vide)', '438-555-0100');

        $this->travelTo($today->copy()->subDay()->setTime(23, 59));
        $this->makeUpdateNote($client, $operator, 'Téléphone', '(vide)', '514-555-0103');

        $this->travelTo($today->copy()->subDays(6)->setTime(10, 0));
        $this->makeUpdateNote($client, $operator, 'Téléphone', '(vide)', '514-555-0104');

        $this->travelTo($today->copy()->subDays(8)->setTime(10, 0));
        $this->makeUpdateNote($client, $operator, 'Téléphone', '(vide)', '514-555-0105');

        // Hors des 30 jours : seule la tuile « Total » la voit.
        $this->travelTo($today->copy()->subDays(31)->setTime(10, 0));
        $this->makeUpdateNote($client, $operator, 'Téléphone', '(vide)', '514-555-0106');

        $this->travelTo($today->copy()->setTime(12, 0));
        Sanctum::actingAs($operator);

        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.role', 'OPERATOR')
            ->assertJsonPath('data.stats.phones_today', 2)
            ->assertJsonPath('data.stats.phones_week', 4)
            ->assertJsonPath('data.stats.phones_month', 5)
            ->assertJsonPath('data.stats.phones_total', 6)
            // Aucun graphique, aucun classement : la page n'en rend pas.
            ->assertJsonPath('data.calls_by_day', [])
            ->assertJsonPath('data.top_commercials', []);
    }

    /**
     * Le tableau de bord de l'opérateur est **instantané** : le filtre de
     * période des gestionnaires ne déplace aucun de ses chiffres (l'UI ne lui
     * rend d'ailleurs ni barre de période ni graphique).
     */
    public function test_operator_dashboard_ignores_period_and_source_filters(): void
    {
        $operator = User::factory()->create(['role' => 'OPERATOR', 'status' => 'ACTIVE']);
        $client = $this->makeClient();

        $this->travelTo(Carbon::today()->setTime(9, 0));
        $this->makeUpdateNote($client, $operator, 'Téléphone', '(vide)', '514-555-0101');
        $this->travelBack();

        Sanctum::actingAs($operator);

        foreach (['?year=1999', '?year=1999&month=2&week=1', '?source=Anglais'] as $query) {
            $this->getJson('/api/v1/dashboard/stats'.$query)
                ->assertOk()
                ->assertJsonPath('data.stats.phones_today', 1)
                ->assertJsonPath('data.stats.phones_total', 1);
        }
    }

    /**
     * Invariant « tuile = lignes rendues » (§9) : `pending_without_phone` est
     * calculé sur le périmètre forcé de l'opérateur (`applyOperatorScope`),
     * vérifié ici contre `GET /commercials/clients` — même exigence que pour
     * les cartes du commercial.
     */
    public function test_operator_pending_tile_equals_his_grande_liste_rows(): void
    {
        $operator = User::factory()->create(['role' => 'OPERATOR', 'status' => 'ACTIVE']);

        // Reste à faire : `AVAILABLE`, hors blacklist, sans numéro — les deux
        // formes de « sans numéro » (`NULL` et chaîne vide) comptent.
        $this->makeClient(['name' => 'Sans numéro 1', 'phone' => null]);
        $this->makeClient(['name' => 'Sans numéro 2', 'phone' => '']);
        $this->makeClient(['name' => 'Sans numéro 3', 'phone' => null]);

        // Trois hors périmètre, chacune pour une raison différente.
        $this->makeClient(['name' => 'Déjà joint', 'phone' => '514-555-0199']);
        $this->makeClient(['name' => 'Blacklisté', 'phone' => null, 'is_blacklisted' => true]);
        $this->makeClient(['name' => 'Réservé', 'phone' => null, 'status' => Client::STATUS_RESERVED]);

        Sanctum::actingAs($operator);

        $dashboard = $this->getJson('/api/v1/dashboard/stats')->assertOk();
        $pending = $dashboard->json('data.stats.pending_without_phone');

        $rows = $this->getJson('/api/v1/commercials/clients?search=Sans%20num%20')
            ->assertOk()
            ->json('data.pagination.total');

        $this->assertSame(3, $pending);
        $this->assertSame($pending, $rows);
    }
}
