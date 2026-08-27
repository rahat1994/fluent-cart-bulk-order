<?php

namespace FluentCartBulkOrder\Integrations\Elementor;

defined('ABSPATH') || exit;

/**
 * Registers the FCBO widgets with Elementor — and only ever runs when Elementor
 * is actually there.
 *
 * ---------------------------------------------------------------------------
 * ELEMENTOR IS NOT A DEPENDENCY
 * ---------------------------------------------------------------------------
 *
 * Elementor is not in composer.json and is not required in the plugin header.
 * Most stores running this plugin will never install it. So the entire
 * integration hangs off a single hook, `elementor/widgets/register`, which
 * cannot fire unless Elementor is loaded.
 *
 * That is also why the widget classes live in SEPARATE FILES that this class
 * requires from inside register(). They extend \Elementor\Widget_Base, so PHP
 * cannot even parse them without Elementor present — requiring them at the top
 * of this file, or from the main plugin file, would turn "Elementor is not
 * installed" into a fatal error. Nothing in THIS file names an Elementor class
 * at parse time, which is what makes it safe to load anywhere.
 *
 * ---------------------------------------------------------------------------
 * THE NAMESPACE TRAP
 * ---------------------------------------------------------------------------
 *
 * This namespace ends in `Elementor`, so an unqualified `Elementor\Widget_Base`
 * inside these files resolves to
 * FluentCartBulkOrder\Integrations\Elementor\Elementor\Widget_Base — a class
 * that does not exist. Every reference to Elementor's own classes in this
 * directory is therefore written fully qualified, with the leading backslash.
 * Do not "tidy" them into `use` statements without checking what they resolve
 * to.
 *
 * @see \FluentCartBulkOrder\Integrations\Elementor\ShortcodeWidget The shared base.
 */
class WidgetHandler
{
    /**
     * Widget class name in this namespace => the file it lives in, which is the
     * same name. Order is the order they appear in Elementor's panel.
     */
    const WIDGETS = [
        'BulkOrderFormWidget',
        'ProductTableWidget',
    ];

    /**
     * @param mixed $widgetsManager Elementor's \Elementor\Widgets_Manager.
     * @return void
     */
    public static function register($widgetsManager)
    {
        // Belt and braces. The hook alone should be proof enough that Elementor
        // is loaded, but a site can fire any hook, and the cost of being wrong
        // here is a fatal error on a page load rather than a missing widget.
        if (!class_exists('\Elementor\Widget_Base') || !is_object($widgetsManager)) {
            return;
        }

        // `register()` replaced `register_widget_type()` in Elementor 3.5, the
        // same release that introduced the `elementor/widgets/register` hook we
        // are on. Checked anyway so a future rename degrades to "no widgets"
        // instead of a fatal.
        if (!method_exists($widgetsManager, 'register')) {
            return;
        }

        require_once __DIR__ . '/ShortcodeWidget.php';

        foreach (self::WIDGETS as $name) {
            require_once __DIR__ . '/' . $name . '.php';

            $class = __NAMESPACE__ . '\\' . $name;

            if (class_exists($class)) {
                $widgetsManager->register(new $class());
            }
        }
    }
}
