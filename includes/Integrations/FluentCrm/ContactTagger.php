<?php

namespace FluentCartBulkOrder\Integrations\FluentCrm;

defined('ABSPATH') || exit;

/**
 * Tags the applicant's FluentCRM contact when they apply, and again when they
 * are approved — and does absolutely nothing on a site without FluentCRM.
 *
 * ---------------------------------------------------------------------------
 * FLUENTCRM IS NOT A DEPENDENCY
 * ---------------------------------------------------------------------------
 *
 * Same rule as the Elementor integration, for the same reason: FluentCRM is not
 * in composer.json, is not in the plugin header, and most stores running this
 * plugin will never install it. @see
 * \FluentCartBulkOrder\Integrations\Elementor\WidgetHandler, which documents
 * the pattern this file follows.
 *
 * The difference from Elementor is that there is no "fluentcrm/init"-shaped
 * hook that can only fire when FluentCRM is loaded — this code runs on OUR
 * actions, which fire whether FluentCRM is there or not. So the guard has to be
 * explicit, and it is the first line of every public method:
 *
 *     defined('FLUENTCRM') && function_exists('FluentCrmApi')
 *
 * NOTHING in this file names a FluentCRM class at parse time. Every call goes
 * through the `FluentCrmApi()` global function, which is FluentCRM's own
 * documented entry point and the only part of it with a stability promise.
 * That is what makes the file safe to load anywhere, and it also sidesteps:
 *
 * ---------------------------------------------------------------------------
 * THE NAMESPACE TRAP
 * ---------------------------------------------------------------------------
 *
 * This namespace ends in `FluentCrm`, and FluentCRM's own classes live under a
 * top-level `FluentCrm\` namespace. So an unqualified `FluentCrm\App\Models\Tag`
 * written in this file resolves to
 * FluentCartBulkOrder\Integrations\FluentCrm\FluentCrm\App\Models\Tag — a class
 * that has never existed. Any reference to a FluentCRM class here MUST be
 * fully qualified with a leading backslash. There are currently none, and
 * keeping it that way is the simplest defence.
 *
 * ---------------------------------------------------------------------------
 * WHY A CRM FAILURE IS SWALLOWED
 * ---------------------------------------------------------------------------
 *
 * Every call is wrapped in try/catch(\Throwable). FluentCRM's PHP API throws on
 * an unknown module, and a contact write can fail on a database error or a
 * plugin that vetoes it. None of that is a reason to fail an application
 * submission or to leave an approval half-done: the role has already been
 * granted by the time this runs, and a missing tag is a marketing problem, not
 * a store problem.
 *
 * The failure is not silent to a developer — it goes through the standard
 * `fcbo/wholesale/crm_error` action so a site can log it.
 */
class ContactTagger
{
    /**
     * Whether FluentCRM is present and usable.
     *
     * Two checks, not one. `FLUENTCRM` says the plugin's main file has run;
     * `FluentCrmApi()` says its helper file has loaded and the container is
     * available. On an early hook the first can be true while the second is
     * not, and calling an undefined function is a fatal.
     *
     * @return bool
     */
    public static function isAvailable()
    {
        return defined('FLUENTCRM') && function_exists('FluentCrmApi');
    }

    /**
     * Every tag the site has, as id => title, for the settings page dropdowns.
     *
     * @return array<int, string> Empty when FluentCRM is not available.
     */
    public static function tagOptions()
    {
        if (!self::isAvailable()) {
            return [];
        }

        try {
            $tags = FluentCrmApi('tags')->all();
        } catch (\Throwable $e) {
            self::reportError('list_tags', $e);

            return [];
        }

        // NOT `(array) $tags`. FluentCRM returns a Collection object, and
        // casting one to an array yields its INTERNAL properties (`items` and
        // friends), not the tags inside it — so the loop below silently found
        // nothing and the settings page said "FluentCRM has no tags yet" on a
        // site with tags. A Collection is Traversable, so iterate it as one and
        // accept a plain array too, in case a future version returns one.
        if (!is_array($tags) && !($tags instanceof \Traversable)) {
            return [];
        }

        $options = [];

        foreach ($tags as $tag) {
            // Models are objects; a plain array is accepted for the same
            // future-proofing reason.
            $id    = is_object($tag) && isset($tag->id) ? $tag->id : (is_array($tag) && isset($tag['id']) ? $tag['id'] : 0);
            $title = is_object($tag) && isset($tag->title) ? $tag->title : (is_array($tag) && isset($tag['title']) ? $tag['title'] : '');

            if ((int) $id <= 0) {
                continue;
            }

            $options[(int) $id] = $title !== '' ? (string) $title : (string) (int) $id;
        }

        return $options;
    }

    /**
     * An applicant submitted — tag their contact.
     *
     * @param int   $userId
     * @param array $record  Unused; part of the action signature.
     * @param string $outcome Unused.
     * @return void
     */
    public static function onSubmitted($userId, $record = [], $outcome = '')
    {
        require_once dirname(dirname(__DIR__)) . '/Wholesale/ApplicationSettings.php';

        self::tagUser($userId, \FluentCartBulkOrder\Wholesale\ApplicationSettings::tagOnApply());
    }

    /**
     * An application was decided — tag the contact if it was an approval.
     *
     * Rejection deliberately applies no tag. There is no obvious right one: a
     * store may want to nurture a rejected applicant, or leave them alone, and
     * guessing would put a tag on a contact the owner never asked for. A site
     * that wants one hooks `fcbo/wholesale/application_reviewed` itself.
     *
     * @param int    $userId
     * @param array  $record Unused.
     * @param string $status
     * @return void
     */
    public static function onReviewed($userId, $record, $status)
    {
        require_once dirname(dirname(__DIR__)) . '/Wholesale/ApplicationStatus.php';
        require_once dirname(dirname(__DIR__)) . '/Wholesale/ApplicationSettings.php';

        if (!\FluentCartBulkOrder\Wholesale\ApplicationStatus::grantsRole($status)) {
            return;
        }

        self::tagUser($userId, \FluentCartBulkOrder\Wholesale\ApplicationSettings::tagOnApprove());
    }

    /**
     * Attach one tag to a user's contact, creating the contact if needed.
     *
     * @param int $userId
     * @param int $tagId  0 means the owner chose no tag — do nothing.
     * @return bool Whether the tag was attached.
     */
    public static function tagUser($userId, $tagId)
    {
        $userId = (int) $userId;
        $tagId  = (int) $tagId;

        // The commonest case on most stores: no FluentCRM, or no tag chosen.
        // Both exit before anything is loaded or queried.
        if ($tagId <= 0 || $userId <= 0 || !self::isAvailable()) {
            return false;
        }

        $user = get_userdata($userId);

        if (!$user || !is_email($user->user_email)) {
            return false;
        }

        try {
            $contact = self::resolveContact($user);

            if (!$contact) {
                return false;
            }

            $contact->attachTags([$tagId]);

            return true;
        } catch (\Throwable $e) {
            self::reportError('tag_user', $e);

            return false;
        }
    }

    /**
     * The user's FluentCRM contact, creating one if they have none.
     *
     * ---------------------------------------------------------------------------
     * THE CONSENT QUESTION, ANSWERED EXPLICITLY
     * ---------------------------------------------------------------------------
     *
     * Creating a contact means putting somebody into a marketing database, so
     * the status matters. This creates them as `subscribed`, on the same basis
     * as a checkout: the person deliberately submitted a business application
     * to this store and expects to hear back about it. A `pending` contact
     * would sit outside every automation, which would make the tag useless and
     * the whole integration pointless.
     *
     * A store that disagrees can change it in one line, per site, through
     * `fcbo/wholesale/crm_contact_status`. A store that wants no contact
     * created at all simply leaves the tag setting empty.
     *
     * An EXISTING contact is never touched beyond the tag — no status change,
     * no name overwrite. Resurrecting an unsubscribed contact because they
     * filled in a form is exactly the escalation FluentCRM's own API refuses to
     * do by default.
     *
     * @param \WP_User $user
     * @return object|null FluentCRM Subscriber model.
     */
    private static function resolveContact($user)
    {
        $contacts = FluentCrmApi('contacts');

        $contact = $contacts->getContactByUserRef($user->ID);

        if ($contact) {
            return $contact;
        }

        /**
         * The status a newly created wholesale applicant's contact gets.
         *
         * @param string   $status One of FluentCRM's contact statuses.
         * @param \WP_User $user
         */
        $status = (string) apply_filters('fcbo/wholesale/crm_contact_status', 'subscribed', $user);

        $created = $contacts->createOrUpdate([
            'email'      => $user->user_email,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'user_id'    => $user->ID,
            'status'     => $status,
        ]);

        return $created ? $created : null;
    }

    /**
     * Hand a CRM failure to the site rather than swallowing it entirely.
     *
     * An action, not a `error_log()` call: a store that wants these in its log
     * hooks it, and one that does not is not given a growing debug.log it never
     * asked for.
     *
     * @param string     $context Which call failed.
     * @param \Throwable $e
     * @return void
     */
    private static function reportError($context, $e)
    {
        /**
         * A FluentCRM call made by this plugin failed.
         *
         * @param string     $context
         * @param \Throwable $e
         */
        do_action('fcbo/wholesale/crm_error', $context, $e);
    }
}
