/**
 * Noted! — Shared icons.
 *
 * Buildless equivalent of `import { Icon, ... } from '@wordpress/icons'`.
 *
 * The SVG markup lives in PHP ({@see Plugin::iconSvg}) and is delivered
 * via `wp_localize_script` as a string. We parse it once into a React
 * element so consumers can pass it by name:
 *
 *   icon: notedIcons.icon                       // plain
 *   icon: notedIcons.withNotification(hasNote)  // with corner dot
 *
 * Generic names (`icon`, `withNotification`) make swapping the SVG a
 * one-line PHP change — no JS edits needed.
 *
 * @module icons
 */
(function (wp, window) {
    if (!wp || !wp.element) {
        return;
    }

    const { createElement: createReactElement } = wp.element;
    const config = window.notedIconsConfig || {};

    /**
     * SVG attribute names need camelCase translation for React. Anything
     * not listed here is passed through unchanged, which works for plain
     * lowercase attributes like `d`, `fill`, `width`.
     */
    const ATTRIBUTE_RENAME_MAP = {
        'class':             'className',
        'fill-rule':         'fillRule',
        'clip-rule':         'clipRule',
        'stroke-width':      'strokeWidth',
        'stroke-linejoin':   'strokeLinejoin',
        'stroke-linecap':    'strokeLinecap',
        'stroke-miterlimit': 'strokeMiterlimit',
        'stroke-dasharray':  'strokeDasharray',
        'stroke-dashoffset': 'strokeDashoffset',
        'stroke-opacity':    'strokeOpacity',
        'fill-opacity':      'fillOpacity',
    };

    let keySeed = 0;

    /**
     * Recursively convert a DOM element into a React element tree.
     * Returns null for non-element nodes (text, comments) so they are
     * skipped — the icon markup has no meaningful text content.
     *
     * @param {Element} node
     * @returns {React.ReactNode|null}
     */
    function nodeToReact(node) {
        if (!node || node.nodeType !== 1) {
            return null;
        }

        const props = { key: `noted-svg-${keySeed++}` };
        for (let attributeIndex = 0; attributeIndex < node.attributes.length; attributeIndex++) {
            const attribute = node.attributes[attributeIndex];
            const propName  = ATTRIBUTE_RENAME_MAP[attribute.name] || attribute.name;
            props[propName] = attribute.value;
        }

        const children = [];
        for (let childIndex = 0; childIndex < node.childNodes.length; childIndex++) {
            const childElement = nodeToReact(node.childNodes[childIndex]);
            if (childElement) {
                children.push(childElement);
            }
        }

        return createReactElement(
            node.nodeName.toLowerCase(),
            props,
            children.length ? children : null
        );
    }

    /**
     * Parse an SVG string into a React element. Returns null when the
     * input is empty or cannot be parsed.
     *
     * @param {string} svgString
     * @returns {React.ReactNode|null}
     */
    function parseSvg(svgString) {
        if (!svgString) {
            return null;
        }
        const parsedDocument = new DOMParser().parseFromString(svgString, 'image/svg+xml');
        if (!parsedDocument || !parsedDocument.documentElement) {
            return null;
        }
        return nodeToReact(parsedDocument.documentElement);
    }

    const icon = parseSvg(config.svg);

    /**
     * Render the icon with a notification dot when `hasNote` is true.
     *
     * @param {boolean} hasNote
     * @returns {React.ReactNode}
     */
    function withNotification(hasNote) {
        if (!hasNote) {
            return icon;
        }
        return createReactElement(
            'span',
            { className: 'noted-icon-with-dot' },
            icon,
            createReactElement('span', { className: 'noted-dot', 'aria-hidden': 'true' })
        );
    }

    window.notedIcons = {
        icon: icon,
        withNotification: withNotification,
    };
})(window.wp, window);
