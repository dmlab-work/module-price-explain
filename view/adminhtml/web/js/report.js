/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
/**
 * Admin price report: runs the explanation on the storefront with the one-time token, falls back to the
 * admin-area JSON, renders every section from the JSON. DOM is built with textContent only.
 */
define(['mage/translate'], function ($t) {
    'use strict';

    var MAX_DEPTH = 9;

    function fmt(text) {
        var args = arguments;

        return text.replace(/%(\d+)/g, function (match, n) {
            return args[n] === undefined ? match : String(args[n]);
        });
    }

    function el(tag, className, children) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }
        (children || []).forEach(function (child) {
            if (child !== null && child !== undefined) {
                node.appendChild(typeof child === 'object' ? child : document.createTextNode(String(child)));
            }
        });

        return node;
    }

    function entries(map) {
        return map && typeof map === 'object' ? Object.keys(map).map(function (key) {
            return [key, map[key]];
        }) : [];
    }

    function value(v) {
        if (v === null || v === undefined) {
            return 'null';
        }
        if (typeof v === 'boolean') {
            return v ? 'yes' : 'no';
        }
        if (typeof v === 'object') {
            return '[' + entries(v).map(function (pair) {
                return value(pair[1]);
            }).join(', ') + ']';
        }

        return String(v);
    }

    function pairs(map) {
        return entries(map).map(function (pair) {
            return pair[0] + '=' + value(pair[1]);
        }).join(', ');
    }

    function amount(v) {
        return v === null || v === undefined ? '—' : v;
    }

    function signed(v) {
        return String(v).charAt(0) === '-' ? v : '+' + v;
    }

    function rates(list) {
        return (list || []).map(function (rate) {
            return rate.code + ' ' + rate.percent + '% ' + rate.amount;
        }).join('; ');
    }

    function named(name, id) {
        return name === null || name === undefined ? '#' + id : name + ' (#' + id + ')';
    }

    function section(title, content) {
        return el('section', 'admin__page-section', [
            el('div', 'admin__page-section-title', [el('span', 'title', [title])])
        ].concat(content));
    }

    function placeholder(text) {
        return el('p', null, [text]);
    }

    /**
     * @param {Array<Array>} rows [label, value]
     */
    function facts(rows) {
        return el('table', 'admin__table-secondary', [el('tbody', null, rows.map(function (row) {
            return el('tr', null, [el('th', null, [row[0]]), el('td', null, [row[1]])]);
        }))]);
    }

    /**
     * @param {string[]} headers
     * @param {Array<{cells: Array, className: (string|undefined)}>} rows
     * @param {Node|undefined} foot
     */
    function grid(headers, rows, foot) {
        return el('table', 'data-grid', [
            el('thead', null, [el('tr', null, headers.map(function (header) {
                return el('th', 'data-grid-th', [header]);
            }))]),
            el('tbody', null, rows.map(function (row) {
                return el('tr', row.className, row.cells.map(function (cell) {
                    return cell instanceof Node && cell.tagName === 'TD' ? cell : el('td', null, [cell]);
                }));
            })),
            foot || null
        ]);
    }

    function customer(data) {
        var parts;

        if (!data) {
            return null;
        }
        parts = [data.guest ? $t('Guest') : data.name || ''];
        if (data.email) {
            parts.push('<' + data.email + '>');
        }
        if (!data.guest) {
            parts.push('(#' + data.id + ')');
        }

        return parts.filter(Boolean).join(' ');
    }

    function orderLink(order, url) {
        var link;

        if (!url) {
            return '#' + order.increment_id;
        }
        link = el('a', null, ['#' + order.increment_id]);
        link.href = url;

        return link;
    }

    function summary(quote, order, orderViewUrl) {
        var rows = [
                [$t('Quote'), '#' + quote.id],
                [$t('Store View'), named(quote.store_name, quote.store)],
                [$t('Website'), named(quote.website_name, quote.website)],
                [$t('Customer Group'), named(quote.group_name, quote.group)]
            ],
            who = customer(quote.customer);

        if (order) {
            rows.unshift([$t('Order'), orderLink(order, orderViewUrl)]);
        }
        if (who !== null) {
            rows.push([$t('Customer'), who]);
        }
        rows.push([$t('Coupon'), quote.coupon || $t('none')], [$t('Currency'), quote.currency]);

        return section($t('Summary'), [facts(rows)]);
    }

    function totals(data, currency) {
        var withBase = data.base_currency !== null && data.base_currency !== currency,
            columns = [[$t('Current'), data.current, false]];

        if (withBase) {
            columns.push([$t('Base Current'), data.base_current, false]);
        }
        if (data.ordered) {
            columns.push([$t('Ordered'), data.ordered, true]);
            if (withBase) {
                columns.push([$t('Base Ordered'), data.base_ordered, false]);
            }
        }

        return section($t('Totals'), [grid(
            [$t('Total')].concat(columns.map(function (column) {
                return column[0];
            })),
            Object.keys(data.labels).map(function (code) {
                var differs = data.differences.indexOf(code) !== -1;

                return {cells: [data.labels[code]].concat(columns.map(function (column) {
                    return column[2] && differs
                        ? el('td', 'dmlab-pe-fail', [amount(column[1][code]) + ' ≠'])
                        : amount(column[1][code]);
                }))};
            })
        )]);
    }

    function layerNote(layer) {
        var note = pairs(layer.details);

        if (layer.rules.length) {
            note += (note === '' ? '' : ', ') + 'rules: ' + layer.rules.join(', ');
        }

        return note;
    }

    function itemFooter(item) {
        var parts = [fmt($t('Final %1 · item price %2'), amount(item.final), amount(item.item_price))],
            cell;

        if (item.custom_price !== null) {
            parts.push(' · ' + fmt($t('custom price %1'), item.custom_price));
        }
        if (item.unexplained_delta !== null) {
            parts.push(' · ', el('strong', 'dmlab-pe-fail', [
                fmt($t('unexplained delta %1'), signed(item.unexplained_delta))
            ]));
            if (item.suspects.length) {
                parts.push(' (' + fmt($t('suspects: %1'), item.suspects.join(', ')) + ')');
            }
        }
        cell = el('td', null, parts);
        cell.colSpan = 4;

        return el('tfoot', null, [el('tr', null, [cell])]);
    }

    function items(list) {
        var content = list.length ? [] : [placeholder($t('No items.'))];

        list.forEach(function (item) {
            content.push(
                el('h3', null, [fmt('%1 "%2" × %3 (product %4)', item.label, item.name, item.qty, item.product_id)]),
                grid(
                    [$t('Layer'), $t('Value'), $t('State'), $t('Details')],
                    item.layers.map(function (layer) {
                        return {
                            className: layer.won ? 'dmlab-pe-won' : '',
                            cells: [layer.label, amount(layer.value), layer.state_label, layerNote(layer)]
                        };
                    }),
                    itemFooter(item)
                )
            );
        });

        return section($t('Item Prices (base currency)'), content);
    }

    function changed(step) {
        return entries(step.delta).length > 0 || entries(step.base_delta).length > 0;
    }

    function collectorRows(steps) {
        var groups = {},
            order = [],
            rows = [];

        steps.forEach(function (step) {
            if (!groups[step.address]) {
                groups[step.address] = [];
                order.push(step.address);
            }
            groups[step.address].push(step);
        });
        order.forEach(function (address) {
            var group = groups[address],
                cell;

            if (!group.some(changed)) {
                cell = el('td', null, [fmt($t('No changes (%1 collectors)'), group.length)]);
                cell.colSpan = 5;
                rows.push({className: 'dmlab-pe-collapsed', cells: [address, cell]});
            }
            group.forEach(function (step) {
                var changes = entries(step.delta).map(function (pair) {
                    return pair[0] + ' ' + signed(pair[1]);
                });

                rows.push({
                    className: changed(step) ? '' : 'dmlab-pe-unchanged',
                    cells: [
                        step.address,
                        step.sort_order === null ? '—' : String(step.sort_order),
                        step.code,
                        step.class,
                        step.third_party ? $t('Yes') : $t('No'),
                        changes.length ? changes.join(', ') : '—'
                    ]
                });
            });
        });

        return rows;
    }

    function collectors(steps) {
        var unchanged = steps.filter(function (step) {
                return !changed(step);
            }).length,
            content = [],
            toggle;

        if (!steps.length) {
            return section($t('Totals Collectors'), [placeholder($t('No collectors traced.'))]);
        }
        if (unchanged > 0) {
            toggle = el('input', 'admin__control-checkbox dmlab-pe-toggle');
            toggle.type = 'checkbox';
            toggle.id = 'dmlab-pe-all-collectors';
            content.push(toggle, el('label', 'admin__field-label', [
                fmt($t('Show all collectors (%1 without changes)'), unchanged)
            ]));
            content[1].htmlFor = toggle.id;
        }
        content.push(grid(
            [$t('Address'), $t('Sort Order'), $t('Code'), $t('Class'), $t('Third-Party'), $t('Changes')],
            collectorRows(steps)
        ));

        return section($t('Totals Collectors'), content);
    }

    function flatten(node, depth, rows) {
        rows.push({node: node, depth: Math.min(depth, MAX_DEPTH)});
        node.children.forEach(function (child) {
            flatten(child, depth + 1, rows);
        });

        return rows;
    }

    /**
     * Compared value; a folded leaf lists each item's value, marked ✓/✗ when their results differ.
     */
    function actualText(node) {
        var members = node.member_actuals,
            marked;

        if (!members) {
            return node.actual_label !== null ? node.actual_label : value(node.actual);
        }
        marked = members.some(function (m) {
            return m.result !== members[0].result;
        });

        return members.map(function (m) {
            return (marked ? (m.result ? '✓ ' : '✗ ') : '') + m.label + ': '
                + (m.actual_label !== null ? m.actual_label : value(m.actual));
        }).join(' · ');
    }

    function treeRow(row) {
        var node = row.node,
            leaf = node.children.length === 0,
            mark = !node.evaluated ? '–' : node.result ? '✓' : '✗',
            markClass = !node.evaluated ? 'dmlab-pe-skip' : node.result ? 'dmlab-pe-pass' : 'dmlab-pe-fail',
            parts = [el('span', markClass, [mark]), ' ', node.text];

        if (leaf && node.item) {
            parts.push(' ', el('span', 'dmlab-pe-detail', ['(' + node.item + ')']));
        }
        if (leaf && node.evaluated) {
            parts.push(' ', el('span', 'dmlab-pe-detail', [fmt(
                $t('expected %1, actual %2'),
                node.expected_label !== null ? node.expected_label : value(node.expected),
                actualText(node)
            )]));
        }
        if (node.note) {
            parts.push(' ', el('span', 'dmlab-pe-detail', ['(' + node.note + ')']));
        }

        return el('li', 'dmlab-pe-depth-' + row.depth, parts);
    }

    function hasTree(node) {
        return !!node && node.children.length > 0;
    }

    /**
     * @param {Array} lead summary content before the toggle
     * @param {string} title
     * @param {Object} node
     * @param {boolean} open
     * @param {string|null} className
     */
    function tree(lead, title, node, open, className) {
        var details = el('details', className, [
            el('summary', null, lead.concat([el('span', 'dmlab-pe-summary', [title])])),
            el('ul', 'dmlab-pe-tree', flatten(node, 0, []).map(treeRow))
        ]);

        details.open = open;

        return details;
    }

    function outcome(data, child) {
        var className = child ? 'dmlab-pe-child' : '',
            parts = [el('strong', null, [data.label]), ': '];

        parts.push(data.applied
            ? el('span', 'dmlab-pe-pass', [fmt($t('applied %1'), amount(data.amount))])
            : el('span', 'dmlab-pe-fail', [$t('not applied')]));
        if (data.stage) {
            parts.push(' [' + data.stage_label + ']');
        }
        if (data.reason) {
            parts.push(' ' + data.reason);
        }
        if (data.stopped_by !== null) {
            parts.push(' ' + fmt($t('(stopped by rule #%1)'), data.stopped_by));
        }
        if (!hasTree(data.actions)) {
            return el('div', className, parts);
        }
        parts.push(' ');

        return tree(parts, $t('Actions'), data.actions, !data.applied && data.stage === 'actions', className);
    }

    function ruleFlags(rule) {
        var flags = [];

        if (rule.free_shipping) {
            flags.push(fmt($t('free shipping (%1)'), rule.free_shipping_label)
                + (rule.free_shipping_outcome ? ': ' + rule.free_shipping_outcome_label : ''));
        }
        if (rule.stop_rules_processing) {
            flags.push($t('discards subsequent rules'));
        }

        return flags;
    }

    function rules(list) {
        var rows = [];

        if (!list.length) {
            return section($t('Cart Price Rules'), [placeholder($t('No cart price rules.'))]);
        }
        list.forEach(function (rule) {
            var flags = ruleFlags(rule),
                name = el('td', null, [rule.name]),
                status = el('td', rule.status === 'applied' ? 'dmlab-pe-pass' : 'dmlab-pe-fail', [rule.status_label]),
                details;

            if (flags.length) {
                name.appendChild(el('br'));
                name.appendChild(el('small', null, [flags.join(' · ')]));
            }
            rows.push({cells: [
                String(rule.id), name, String(rule.sort_order), status, rule.stage_label || '', rule.reason || '',
                amount(rule.amount), amount(rule.shipping_amount)
            ]});
            if (hasTree(rule.conditions) || rule.items.length) {
                details = el('td', null, [hasTree(rule.conditions) ? tree(
                    [], $t('Conditions'), rule.conditions, rule.status === 'rejected' && rule.stage === 'conditions', null
                ) : null]);
                details.colSpan = 7;
                rule.items.forEach(function (parent) {
                    details.appendChild(outcome(parent, false));
                    parent.children.forEach(function (child) {
                        details.appendChild(outcome(child, true));
                    });
                });
                rows.push({className: 'dmlab-pe-rule-details', cells: [el('td'), details]});
            }
        });

        return section($t('Cart Price Rules'), [grid([
            $t('ID'), $t('Rule'), $t('Priority'), $t('Status'), $t('Stage'), $t('Reason'),
            $t('Discount'), $t('Shipping Discount')
        ], rows)]);
    }

    function freeShipping(data) {
        var carrier = data.free_shipping_carrier,
            granted = data.rules.map(function (rule) {
                return '#' + rule.id + ' ' + rule.name + ' (' + rule.mode_label + ')';
            }),
            free = data.items.map(function (item) {
                return item.sku + ' × ' + item.qty;
            }),
            effective = data.verdict === 'effective' || data.verdict === 'no_method' && data.method === null;

        return section($t('Free Shipping'), [
            el('p', null, [
                el('strong', effective ? 'dmlab-pe-pass' : 'dmlab-pe-fail', [data.verdict_label]), ' ', data.message
            ]),
            facts([
                [$t('Granted by'), granted.length ? granted.join(', ') : '—'],
                [$t('Address flag'), value(data.address_flag)],
                [$t('Free items'), free.length ? free.join(', ') : '—'],
                [$t('Free boxes'), fmt('%1 of %2', data.free_boxes, data.package_qty)],
                [$t('Method'), data.method || '—'],
                [$t('Stored rate (base currency)'), amount(data.rate_price)
                    + (data.rate_updated_at ? ' (saved ' + data.rate_updated_at + ')' : '')
                    + (data.rate_older_than_quote ? ', older than the quote' : '')],
                [$t('Carrier honours'), data.semantics_label],
                [$t('Free Shipping carrier'), carrier.enabled_label
                    + (carrier.enabled && carrier.subtotal !== null ? ', minimum ' + carrier.subtotal : '')]
            ])
        ]);
    }

    function tax(data) {
        var rows,
            content;

        if (!data) {
            return section($t('Tax'), [placeholder($t('No tax information.'))]);
        }
        rows = [
            [$t('Based On'), data.based_on_label],
            [$t('Algorithm'), data.algorithm_label],
            [$t('Customer Tax Class'), data.customer_tax_class || $t('none')],
            [$t('Address'), data.address_label]
        ].concat(entries(data.flags).map(function (pair) {
            return [data.flag_labels[pair[0]], value(pair[1])];
        }));
        if (data.applied_taxes.length) {
            rows.push([$t('Applied Taxes'), rates(data.applied_taxes)]);
        }
        content = [facts(rows)];
        if (data.lines.length) {
            content.push(grid(
                [$t('Line'), $t('Tax Class'), $t('Tax'), $t('Rates / Reason')],
                data.lines.map(function (line) {
                    return {cells: [
                        line.label, line.tax_class || '—', amount(line.amount),
                        line.rates.length ? rates(line.rates) : line.reason || ''
                    ]};
                })
            ));
        }

        return section($t('Tax'), content);
    }

    function warnings(list) {
        return section($t('Warnings'), [list.length ? el('ul', null, list.map(function (warning) {
            var item = el('li', null, [warning.message]);

            item.title = warning.code;

            return item;
        })) : placeholder($t('None.'))]);
    }

    function render(report, config) {
        return [
            summary(report.quote, report.order, config.orderViewUrl),
            report.totals ? totals(report.totals, report.quote.currency) : null,
            items(report.items),
            collectors(report.collectors),
            rules(report.rules),
            report.free_shipping ? freeShipping(report.free_shipping) : null,
            tax(report.tax),
            warnings(report.warnings)
        ].filter(Boolean);
    }

    function request(url, options) {
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (body) {
                var detail = body && (body.message || body.error);

                if (!response.ok || !body || !body.quote) {
                    throw new Error((detail ? detail + ' ' : '') + '(HTTP ' + response.status + ')');
                }

                return body;
            });
        });
    }

    function storefront(config) {
        // text/plain keeps a cross-origin POST "simple": no preflight, no cookies.
        return request(config.endpointUrl, {
            method: 'POST',
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
            headers: {'Content-Type': 'text/plain'},
            body: 'token=' + config.token
        });
    }

    function adminArea(config) {
        var url = config.fallbackUrl;

        if (window.FORM_KEY) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'form_key=' + encodeURIComponent(window.FORM_KEY);
        }

        return request(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
    }

    function download(report, fileName) {
        var link = document.createElement('a'),
            blob = new Blob([JSON.stringify(report, null, 4)], {type: 'application/json'});

        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(link.href);
    }

    return function (config, element) {
        var status = element.querySelector('[data-role="status"]'),
            sections = element.querySelector('[data-role="sections"]'),
            button = element.querySelector('[data-role="download"]'),
            report = null;

        function show(data, failure) {
            report = data;
            if (failure) {
                status.className = 'message message-warning';
                status.textContent = fmt(
                    $t('The storefront run failed: %1. Showing the admin-area result instead; see Warnings.'),
                    failure.message
                );
            } else {
                status.remove();
            }
            sections.replaceChildren.apply(sections, render(data, config));
            button.disabled = false;
        }

        button.addEventListener('click', function () {
            if (report) {
                download(report, config.fileName);
            }
        });

        storefront(config).then(function (data) {
            show(data, null);
        }, function (failure) {
            return adminArea(config).then(function (data) {
                show(data, failure);
            });
        }).catch(function (error) {
            status.className = 'message message-error';
            status.textContent = fmt($t('The report could not be built: %1'), error.message);
        });
    };
});
