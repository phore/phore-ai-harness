(generation) => {
    'use strict';

    if (typeof generation !== 'string' || !/^[a-zA-Z0-9_-]{1,100}$/.test(generation)) {
        throw new Error('Browser DOM reader needs a valid session generation.');
    }

    const originalDocument = document;
    const encoder = new TextEncoder();
    const byId = new Map();
    const byElement = new WeakMap();
    const snapshots = new Map();
    const omittedTags = new Set(['script', 'style']);
    const voidTags = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
    const maxReferences = 4096;
    const maxScannedNodes = 4096;
    const maxSnapshotNodes = 512;
    const maxSnapshotBytes = 262144;
    const maxRecordBytes = 3072;
    let nextNode = 0;
    let nextSnapshot = 0;

    const byteLength = value => encoder.encode(value).length;
    // Der PHP-Harness maskiert U+2028/U+2029 auch mit JSON_UNESCAPED_UNICODE.
    const jsonBytes = value => byteLength(JSON.stringify(value).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029'));
    const escapeHtml = value => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    // Vor der UTF-8-Messung begrenzen, damit einzelne große Attribute keine große Kopie erzeugen.
    function clipped(value, maxBytes) {
        const source = String(value);
        let result = '';
        let size = 0;
        for (const character of source.slice(0, maxBytes)) {
            const bytes = byteLength(character);
            if (size + bytes > maxBytes) {
                break;
            }
            result += character;
            size += bytes;
        }
        return {value: result, truncated: result.length < source.length};
    }

    function reference(element) {
        const known = byElement.get(element);
        if (known && byId.get(known) === element) {
            byId.delete(known);
            byId.set(known, element);
            return known;
        }
        if (byId.size >= maxReferences) {
            const expired = byId.keys().next().value;
            byElement.delete(byId.get(expired));
            byId.delete(expired);
        }
        const id = generation + '.n' + (++nextNode);
        byId.set(id, element);
        byElement.set(element, id);
        return id;
    }

    function resolve(nodeId) {
        if (typeof nodeId !== 'string' || !nodeId.startsWith(generation + '.n')) {
            throw new Error('Unknown or stale browser node ID. Query the current page again.');
        }
        const element = byId.get(nodeId);
        if (!element || !element.isConnected || element.getRootNode({composed: true}) !== document) {
            throw new Error('Unknown, detached or expired browser node ID. Query the current page again.');
        }
        byId.delete(nodeId);
        byId.set(nodeId, element);
        return element;
    }

    function integer(value, fallback, min, max, name) {
        const result = value === undefined ? fallback : value;
        if (!Number.isInteger(result) || result < min || result > max) {
            throw new Error(name + ' must be an integer between ' + min + ' and ' + max + '.');
        }
        return result;
    }

    function rootFor(nodeId, html) {
        if (nodeId !== '') {
            return resolve(nodeId);
        }
        const root = html ? document.body || document.documentElement : document.documentElement;
        if (!root) {
            throw new Error('The current document has no DOM root.');
        }
        return root;
    }

    function pageMetadata() {
        const url = location.href.startsWith('data:') ? 'data:[omitted]' : clipped(location.href, 512).value;
        return {url, title: clipped(document.title, 192).value};
    }

    function directText(element) {
        let result = '';
        let child = element.firstChild;
        let scanned = 0;
        let truncated = false;
        while (child && scanned < 64) {
            scanned++;
            if (child.nodeType === Node.TEXT_NODE) {
                const part = clipped(child.substringData(0, 256), 256);
                result += part.value;
                truncated ||= part.truncated || child.length > part.value.length;
                if (byteLength(result) >= 256) {
                    truncated ||= child.nextSibling !== null;
                    break;
                }
            }
            child = child.nextSibling;
        }
        truncated ||= child !== null && scanned >= 64;
        const final = clipped(result, 256);
        return {value: final.value, truncated: truncated || final.truncated};
    }

    function record(entry) {
        const element = entry.element;
        const tag = clipped(element.localName, 128);
        const truncatedFields = [];
        const names = [];
        const record = {
            nodeId: reference(element),
            parentId: entry.parent ? reference(entry.parent) : null,
            depth: entry.depth,
            tag: tag.value,
            html: '',
            childCount: element.children.length,
            hasShadowRoot: Boolean(element.shadowRoot),
        };
        if (entry.shadowHost) {
            record.shadowHostId = reference(entry.shadowHost);
        }
        if (element.shadowRoot) {
            record.shadowChildCount = element.shadowRoot.children.length;
        }
        if (element.localName === 'template') {
            record.templateContentOmitted = true;
        }
        if (element.hasAttribute('style')) {
            record.hasInlineStyle = true;
        }
        if (tag.truncated) {
            truncatedFields.push('tag');
        }

        const text = directText(element);
        if (text.truncated) {
            truncatedFields.push('text');
        }
        const close = voidTags.has(element.localName) ? '' : escapeHtml(text.value) + '</' + tag.value + '>';
        let opening = '<' + tag.value;
        const attributes = element.attributes;
        const count = Math.min(attributes.length, 64);
        for (let index = 0; index < count; index++) {
            const attribute = attributes[index];
            const name = attribute.name;
            if (name.toLowerCase() === 'style' || /^on/i.test(name)) {
                continue;
            }
            if (name.length > 128 || /[\s"'<>/=]/u.test(name)) {
                truncatedFields.push('attribute-name');
                continue;
            }
            let value = clipped(attribute.value, 512);
            if (/^\s*data:/i.test(attribute.value.slice(0, 32))) {
                value = {value: '[data URL omitted]', truncated: true};
            } else if (/^\s*javascript:/i.test(attribute.value.slice(0, 32))) {
                value = {value: '[script URL omitted]', truncated: true};
            } else if (name.toLowerCase() === 'srcdoc') {
                value = {value: '[embedded HTML omitted]', truncated: true};
            }
            const addition = ' ' + name + '="' + escapeHtml(value.value) + '"';
            record.html = opening + addition + '>' + close;
            if (jsonBytes(record) > maxRecordBytes - 384) {
                truncatedFields.push('attributes');
                break;
            }
            opening += addition;
            names.push(name);
            if (value.truncated && truncatedFields.length < 12) {
                truncatedFields.push('attribute:' + name);
            }
        }
        if (attributes.length > count) {
            truncatedFields.push('attributes');
        }
        record.html = opening + '>' + close;
        record.attributeCount = attributes.length;
        record.attributesReturned = names.length;
        if (truncatedFields.length > 0) {
            record.truncatedFields = [...new Set(truncatedFields)].slice(0, 16);
        }
        if (jsonBytes(record) > maxRecordBytes) {
            record.html = '<' + tag.value + '>' + close;
            record.attributesReturned = 0;
            record.truncatedFields = ['attributes'];
            if (text.truncated) {
                record.truncatedFields.push('text');
            }
            if (tag.truncated) {
                record.truncatedFields.push('tag');
            }
        }
        return record;
    }

    // Iterative Tiefensuche ohne vollständige NodeLists, outerHTML oder textContent anzulegen.
    function* elements(root, includeShadowDom, maxDepth, status) {
        const stack = [{next: root, only: true, parent: null, depth: 0, shadowHost: null}];
        while (stack.length > 0) {
            const frame = stack[stack.length - 1];
            if (!frame.next) {
                stack.pop();
                continue;
            }
            if (status.scanned >= maxScannedNodes) {
                status.scanTruncated = true;
                break;
            }
            const element = frame.next;
            frame.next = frame.only ? null : element.nextElementSibling;
            status.scanned++;
            if (omittedTags.has(element.localName)) {
                continue;
            }
            yield {element, parent: frame.parent, depth: frame.depth, shadowHost: frame.shadowHost};

            const light = element.localName === 'template' ? null : element.firstElementChild;
            const shadow = includeShadowDom ? element.shadowRoot : null;
            if (frame.depth >= maxDepth) {
                status.depthLimited ||= light !== null || Boolean(shadow && shadow.firstElementChild);
                continue;
            }
            if (shadow && shadow.firstElementChild) {
                stack.push({next: shadow.firstElementChild, only: false, parent: element, depth: frame.depth + 1, shadowHost: element});
            }
            if (light) {
                stack.push({next: light, only: false, parent: element, depth: frame.depth + 1, shadowHost: frame.shadowHost});
            }
        }
    }

    function makeSnapshot(operation, root, depth, selector, includeShadowDom, key) {
        const status = {scanned: 0, scanTruncated: false, depthLimited: false};
        const nodes = [];
        let bytes = 0;
        let fieldsTruncated = false;
        for (const entry of elements(root, includeShadowDom, depth, status)) {
            if (operation === 'query' && !entry.element.matches(selector)) {
                continue;
            }
            const item = record(entry);
            const itemBytes = jsonBytes(item);
            if (nodes.length >= maxSnapshotNodes || bytes + itemBytes > maxSnapshotBytes) {
                status.scanTruncated = true;
                break;
            }
            nodes.push(item);
            bytes += itemBytes;
            fieldsTruncated ||= Boolean(item.truncatedFields);
        }
        const snapshot = {
            ...pageMetadata(),
            id: generation + '.s' + (++nextSnapshot),
            key,
            format: operation === 'html' ? 'dom-outline' : 'element-list',
            rootNodeId: reference(root),
            nodes,
            fieldsTruncated,
            scanTruncated: status.scanTruncated,
            depthLimited: status.depthLimited,
        };
        if (snapshots.size >= 4) {
            snapshots.delete(snapshots.keys().next().value);
        }
        snapshots.set(snapshot.id, snapshot);
        return snapshot;
    }

    function page(snapshot, offset, limit, maxBytes) {
        const result = {
            snapshotId: snapshot.id,
            format: snapshot.format,
            url: snapshot.url,
            title: snapshot.title,
            rootNodeId: snapshot.rootNodeId,
            nodes: [],
            returned: 0,
            totalNodes: snapshot.nodes.length,
            hasMore: false,
            nextCursor: null,
            truncated: snapshot.scanTruncated || snapshot.depthLimited || snapshot.fieldsTruncated,
            scanTruncated: snapshot.scanTruncated,
            depthLimited: snapshot.depthLimited,
        };
        let index = offset;
        while (index < snapshot.nodes.length && result.nodes.length < limit) {
            let item = snapshot.nodes[index];
            result.nodes.push(item);
            result.returned = result.nodes.length;
            result.hasMore = index + 1 < snapshot.nodes.length;
            result.nextCursor = result.hasMore ? snapshot.id + ':' + (index + 1) : null;
            result.truncated = snapshot.scanTruncated || snapshot.depthLimited || snapshot.fieldsTruncated || result.hasMore;
            if (jsonBytes(result) > maxBytes) {
                result.nodes.pop();
                if (result.nodes.length > 0) {
                    break;
                }
                // Auch ein einzelnes übergroßes Element bleibt referenzierbar; die Kürzung ist sichtbar.
                item = {
                    ...item,
                    html: '<' + item.tag + '>' + (voidTags.has(item.tag) ? '' : '</' + item.tag + '>'),
                    attributesReturned: 0,
                    truncatedFields: ['html', 'attributes', 'text'],
                };
                result.nodes.push(item);
                result.truncated = true;
                if (jsonBytes(result) > maxBytes) {
                    result.url = clipped(result.url, 128).value;
                    result.title = clipped(result.title, 64).value;
                    result.metadataTruncated = true;
                }
                if (jsonBytes(result) > maxBytes) {
                    throw new Error('Browser DOM page budget is too small for one node and its metadata.');
                }
            }
            index++;
        }
        result.returned = result.nodes.length;
        result.hasMore = index < snapshot.nodes.length;
        result.nextCursor = result.hasMore ? snapshot.id + ':' + index : null;
        result.truncated ||= result.hasMore;
        if (jsonBytes(result) > maxBytes) {
            throw new Error('Browser DOM page exceeded its configured output budget.');
        }
        return result;
    }

    return (operation, args = {}) => {
        if (document !== originalDocument) {
            throw new Error('Browser document changed. Open or reload the page before inspecting it.');
        }
        if (operation === 'resolve') {
            return resolve(args.nodeId);
        }
        if (operation !== 'html' && operation !== 'query') {
            throw new Error('Unknown browser DOM operation.');
        }
        const html = operation === 'html';
        const nodeId = html ? args.nodeId ?? '' : args.rootNodeId ?? '';
        if (typeof nodeId !== 'string') {
            throw new Error('Browser node ID must be a string.');
        }
        const depth = html ? integer(args.depth, 3, 0, 10, 'depth') : maxScannedNodes;
        const limit = integer(args.limit, html ? 80 : 20, 1, html ? 200 : 50, 'limit');
        const maxBytes = integer(args.maxBytes, html ? 24576 : 8192, 2048, 32768, 'maxBytes');
        const includeShadowDom = html || args.includeShadowDom === true;
        const selector = html ? '' : args.selector;
        if (!html) {
            if (typeof selector !== 'string' || selector.length > 2000 || selector.trim() === '' || byteLength(selector) > 2000) {
                throw new Error('Provide a non-empty CSS selector of at most 2000 bytes.');
            }
            // matches() bezieht :scope auf den Kandidaten. Eine falsche Scope-Auswertung wäre irreführend.
            if (/:scope\b/i.test(selector)) {
                throw new Error('Bounded browser queries do not support :scope. Use rootNodeId and a descendant selector.');
            }
            document.createDocumentFragment().querySelector(selector);
        }
        const key = JSON.stringify([operation, nodeId, depth, selector, includeShadowDom]);
        const cursor = args.cursor ?? '';
        let snapshot;
        let offset = 0;
        if (cursor !== '') {
            if (typeof cursor !== 'string' || cursor.length > 180) {
                throw new Error('Invalid browser DOM cursor.');
            }
            const separator = cursor.lastIndexOf(':');
            const id = cursor.slice(0, separator);
            const number = cursor.slice(separator + 1);
            snapshot = snapshots.get(id);
            if (!snapshot || snapshot.key !== key || !/^(0|[1-9][0-9]*)$/.test(number)) {
                throw new Error('Unknown, expired or mismatched browser DOM cursor. Start the query again.');
            }
            offset = Number(number);
            if (!Number.isSafeInteger(offset) || offset > snapshot.nodes.length) {
                throw new Error('Browser DOM cursor offset is outside the cached snapshot.');
            }
        } else {
            snapshot = makeSnapshot(operation, rootFor(nodeId, html), depth, selector, includeShadowDom, key);
        }
        return page(snapshot, offset, limit, maxBytes);
    };
}
