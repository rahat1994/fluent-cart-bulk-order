<?php

namespace FluentCartBulkOrder\Integrations\Elementor;

use FluentCartBulkOrder\Shortcodes\AttributeSchema;
use FluentCartBulkOrder\Shortcodes\ShortcodeHandler;

defined('ABSPATH') || exit;

/**
 * Shared base for every FCBO Elementor widget: controls in, shortcode out.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE ONLY PARSES WITH ELEMENTOR LOADED
 * ---------------------------------------------------------------------------
 *
 * It extends \Elementor\Widget_Base, so it must only ever be required from
 * inside the `elementor/widgets/register` hook. @see WidgetHandler, which is the
 * one place that does so, and which explains why the Elementor class names in
 * this directory are all written fully qualified.
 *
 * ---------------------------------------------------------------------------
 * CONTROLS ARE DRIVEN BY THE SCHEMA, NOT BY THIS FILE
 * ---------------------------------------------------------------------------
 *
 * register_controls() walks \FluentCartBulkOrder\Shortcodes\AttributeSchema and
 * asks the subclass for a definition per attribute — rather than the subclass
 * listing controls and the schema being consulted afterwards. That direction is
 * deliberate: it makes "the schema gained an attribute and the Elementor widget
 * never got a control for it" impossible, which is the failure a store owner
 * would experience as a setting that exists in the shortcode and in Gutenberg
 * but nowhere in Elementor.
 *
 * ---------------------------------------------------------------------------
 * EVERY CONTROL HAS A "USE STORE DEFAULT" POSITION
 * ---------------------------------------------------------------------------
 *
 * Every control defaults to '' and AttributeSchema drops empty values, so an
 * untouched control passes no attribute at all and the store-wide default in
 * Settings keeps applying. This is why the yes/no settings are three-option
 * SELECTs and not Elementor SWITCHERs: a switcher reports '' when off, so its
 * off position and "not set" would be the same value and an owner could never
 * say "hide the search box even though the store shows it by default".
 */
abstract class ShortcodeWidget extends \Elementor\Widget_Base
{
    /**
     * The shortcode tag this widget wraps. Must be a key of
     * AttributeSchema::SCHEMA.
     *
     * @return string
     */
    abstract protected function shortcodeTag();

    /**
     * Elementor control arguments per attribute name.
     *
     * Keys must cover every attribute AttributeSchema declares for this tag;
     * see register_controls() for what happens when one is missing.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function controlDefinitions();

    /**
     * @inheritDoc
     */
    public function get_categories()
    {
        // Elementor's built-in "General" category. A category of our own would
        // need a second hook (`elementor/elements/categories_registered`) and
        // more Elementor surface area for two widgets - not worth it.
        return ['general'];
    }

    /**
     * @inheritDoc
     */
    protected function register_controls()
    {
        $tag = $this->shortcodeTag();
        $definitions = $this->controlDefinitions();

        $this->start_controls_section('fcbo_settings', [
            'label' => esc_html__('Settings', 'fluent-cart-bulk-order'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        foreach (array_keys(AttributeSchema::controls($tag)) as $name) {
            $this->add_control($name, $this->controlArgs($name, $definitions));
        }

        $this->end_controls_section();
    }

    /**
     * Control arguments for one attribute, with a usable fallback.
     *
     * A missing definition is a developer mistake, but the worst outcome for a
     * store owner is a setting they cannot reach at all, so the attribute still
     * gets a plain text control. The notice tells the developer; the fallback
     * keeps the store working.
     *
     * @param string                              $name        Attribute name.
     * @param array<string, array<string, mixed>> $definitions From the subclass.
     * @return array<string, mixed>
     */
    private function controlArgs($name, array $definitions)
    {
        if (isset($definitions[$name])) {
            // '' is the "not set" value AttributeSchema drops, so it is the
            // right default for every control regardless of type - but a
            // subclass may still override it deliberately.
            return array_merge(['default' => ''], $definitions[$name]);
        }

        _doing_it_wrong(
            get_class($this) . '::controlDefinitions',
            sprintf(
                /* translators: %s: shortcode attribute name. */
                esc_html__('No Elementor control is defined for the "%s" attribute, so it fell back to a plain text field.', 'fluent-cart-bulk-order'),
                esc_html($name)
            ),
            'FCBO'
        );

        return [
            'label'   => esc_html($name),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ];
    }

    /**
     * @inheritDoc
     */
    protected function render()
    {
        $tag = $this->shortcodeTag();

        // Required rather than assumed: an Elementor render can happen inside an
        // editor AJAX request where nothing else has pulled the shortcode layer
        // in. require_once costs one included-file check.
        require_once FCBO_DIR . 'includes/Shortcodes/ShortcodeHandler.php';

        // get_settings_for_display() returns Elementor's own settings alongside
        // ours; AttributeSchema keeps only the attribute names it declares, so
        // nothing else can reach the shortcode.
        $settings = $this->get_settings_for_display();
        $settings = is_array($settings) ? $this->normalizeSettings($settings) : [];

        $atts = AttributeSchema::toShortcodeAtts($tag, $settings);

        // Deliberately unescaped: this is rendered shortcode markup, escaped by
        // the shortcode class that produced it, exactly as do_shortcode() would
        // return it. Escaping here would print HTML as text.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo ShortcodeHandler::renderTag($tag, $atts);
    }

    /**
     * Last chance to reshape Elementor's stored values into what a shortcode
     * attribute can carry. Passthrough by default.
     *
     * Some Elementor controls do not store a plain string - the URL control
     * stores `['url' => ..., 'is_external' => ..., 'nofollow' => ...]`, for
     * example. AttributeSchema treats a non-scalar as "not set", so without a
     * fix-up the control would look like it saved and then quietly do nothing.
     *
     * This hook exists so those fix-ups live on the widget that chose the
     * control, instead of teaching AttributeSchema about one page builder's
     * storage conventions. Overriding Elementor's own
     * get_settings_for_display() would work too, but that method has a
     * single-setting mode this code has no reason to reimplement.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function normalizeSettings(array $settings)
    {
        return $settings;
    }

    /**
     * The three positions every yes/no setting offers.
     *
     * @param string $onLabel  Label for the "on" position.
     * @param string $offLabel Label for the "off" position.
     * @return array<string, string>
     */
    protected function ternaryOptions($onLabel, $offLabel)
    {
        return [
            ''      => esc_html__('Use store default', 'fluent-cart-bulk-order'),
            'true'  => $onLabel,
            'false' => $offLabel,
        ];
    }

    /**
     * The `roles` control, which both widgets expose identically.
     *
     * @param string $help Surface-specific help text.
     * @return array<string, mixed>
     */
    protected function rolesControl($help)
    {
        return [
            'label'       => esc_html__('Extra roles', 'fluent-cart-bulk-order'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'description' => $help,
        ];
    }
}
