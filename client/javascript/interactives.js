
(function () {
    var loaded = false;

    var config = {
        remember: false,         // remember the user through requests
        trackviews: false,
        trackclicks: true,      // should clicks be
        trackforward: true,     // should target links be hooked and have a uid appended?
        items: [],
        tracker: 'Local',
        endpoint: '',
        cookieprefix: 'int_',
        item: null,
    };

    var uuid = null;

    var allowed_add_actions = ['prepend', 'append', 'before', 'after', 'html'];

    var legacy_add_mapping = {
        'before': 'beforebegin',
        'prepend': 'afterbegin',
        'append': 'beforeend',
        'after': 'afterend',
        'html': 'html'
    };

    var current_id = get_url_param('int_id');

    /**
     * A list of the interactive elements we'll try to add again later
     * for any dynamic elements added to the page.
     *
     * @type Array
     */
    var add_later = {};

    window.SS_ADD = add_later;

    var recorded = {};

    var uid = url_uuid();

    var defaultTracker = '';

    var tracker = {
        track: function (ids, event, uid) {
            var idsArr = ("" + ids).split(',');
            if (idsArr.length <= 0) {
                return;
            }

            var campaign = findCampaignFor(idsArr[0]);

            var trackFn = null;

            if (campaign && campaign.trackIn && Trackers[campaign.trackIn]) {
                trackFn = Trackers[campaign.trackIn].track;
            } else {
                trackFn = Trackers[defaultTracker].track;
            }


            if (trackFn) {
                trackFn(ids, event, uid);
            } else {
                if (window.console && window.console.log) {
                    console.log("Failed to find interactives endpoints");
                }
            }
        }
    };

    var Trackers = {};

    document.addEventListener('DOMContentLoaded', function () {
        if (!window.SSInteractives) {
            // do a timer because sometimes this lib loads before the rest of the
            // page has had a chance.
            setTimeout(function () {
                if (window.SSInteractives) {
                    init_interactives();
                }
            }, 5000);

            return;
        }

        init_interactives();
    });

    function init_interactives() {
        if (loaded) {
            return;
        }

        loaded = true;
        for (var property in window.SSInteractives.config) {
            config[property] = window.SSInteractives.config[property];
        }

        if (!config.endpoint) {
            var baseEl = document.querySelector('base');
            var base = '';
            if (baseEl) {
                base = baseEl.getAttribute('href');
            }
            config.endpoint = base + 'int-act/trk';
        }

        defaultTracker = config.tracker ? config.tracker : 'Local';

        // bind globally available API endpoints now
        window.SSInteractives.addInteractiveItem = addInteractiveItem;
        window.SSInteractives.track = tracker.track;


        // see if we have any items to display
        if (config.campaigns.length) {
            for (var j = 0; j < config.campaigns.length; j++) {
                addCampaign(config.campaigns[j]);
            }
        }

        // record that a page was loaded because of an interaction with a previous interactive
        if (uid && current_id && config.trackforward) {
            tracker.track(current_id, 'int');
        }

        if (config.trackclicks) {
            // see https://gomakethings.com/you-should-always-attach-your-vanilla-js-click-events-to-the-window/
            document.documentElement.addEventListener('click', function (e) {
                if (e.target.matches('.int-submitted')) {
                    return;
                }
                if (e.target.matches('.int-link')) {
                    return recordClick.call(e.target, e);
                }
            });
        }

        triggerEvent(document, 'ss_interactives_inited');

        processViews();

        setTimeout(reprocess, 5000);
    }

    function recordClick(e) {
        var target = this.tagName.toLowerCase();
        var isLink = target === 'a';
        var navLink = this.getAttribute('href');
        var newWindow = this.getAttribute('target') === '_blank';

        // is there a specific type of click
        var clickType = this.getAttribute('data-int-type');
        if (!clickType) {
            clickType = 'clk';
        }

        // was it directly clicked, or clicked into a new tab?
        // if it was middleclicked, we still want to record, but we don't
        // handle the directClick
        var navigateClick = isLink ? (e.which == 1 && !(e.shiftKey || e.ctrlKey)) : false;

        if (navigateClick) {
            if (!navLink) {
                navLink = "";
            }
            // check whether it's an in-page hash link.
            // checks for direct #link, http://fqdn/path/?param=1# and /path?param=2#
            if (navLink.indexOf('#') === 0 ||
                navLink.indexOf(window.location + window.location.search + "#") === 0 ||
                navLink.indexOf(window.location.pathname + window.location.search + "#") === 0
            ) {
                navigateClick = false;
            } else {

            }
        }

        var adId = this.getAttribute('data-intid');
        if (e.which < 3) {
            tracker.track(adId, clickType);
        }

        if (this.classList.contains('hide-on-interact')) {
            var blocked = get_cookie('interacted');
            blocked += '|' + adId;
            set_cookie('interacted', blocked);
        }

        // if we're opening locally, capture the click, and
        // location.href things. This allows the analytics to
        // load before the page unload is triggered.
        if (navigateClick && isLink) {
            // stop the navigation happening; it'll be picked up later and window.location = redirected
            e.preventDefault();

            setTimeout(function () {
                if (newWindow) {
                    window.open(navLink);
                } else {
                    window.location.href = navLink;
                }

            }, 200);
        }

        // or was it a form?
        if (target === 'input' || target === 'button') {
            if ((this.getAttribute('type') === 'submit' || target === 'button') &&
                !this.classList.contains('int-submitted')) {

                this.classList.add('int-submitted');
                // submit the parent form
                e.preventDefault();
                // var form = $(this).parents('form');
                this.setAttribute('data-original-value', this.value);
                this.setAttribute('value', 'Please wait...');
                var _this = this;
                // re-click the button now that we have our blocking class applied
                setTimeout(function () {
                    _this.click();
                }, 200);
            }
        }
    };

    /**
     * Processes all the views of ads on the current page
     *
     * @returns
     */
    function processViews() {
        var ads = document.querySelectorAll('.int-track-view');
        var ids = [];
        for (var i = 0, c = ads.length; i < c; i++) {
            var adId = ads[i].getAttribute('data-intid');
            if (recorded[adId]) {
                continue;
            }
            recorded[adId] = true;
            ids.push(adId);
        }

        if (ids.length) {
            tracker.track(ids.join(','), 'imp');
        }
    }

    function reprocess() {
        for (var id in add_later) {
            addInteractiveItem(add_later[id]);
        }
        processViews();

        setTimeout(reprocess, 3000);
    }



    /**
     * Add a whole campaign to the page.
     *
     * @param object campaign
     * @returns
     */
    function addCampaign(campaign) {
        if (!canShow(campaign)) {
            console.log("Not showing ", campaign);
            return;
        }

        if (campaign.interactives.length) {
            var cookie_name = 'cmp_' + campaign.id;
            // see what type; if it's all, or just a specific ID to show
            var showId = 0;
            var showIndex = 0;
            var item = null;

            if (campaign.display == 'stickyrandom') {
                // if we already have a specific ID in a cookie, we need to use that
                var savedId = get_cookie(cookie_name);
                if (savedId) {
                    showId = savedId;
                } else {
                    // okay, get a new random

                }
            }

            // now check for random / stickyrandom if needbe
            var added = false;

            if (!showId && campaign.display !== 'all') {
                showIndex = Math.floor(Math.random() * (campaign.interactives.length));

                item = campaign.interactives[showIndex];
                // if it's sticky, we need to save a cookie
                if (campaign.display == 'stickyrandom') {
                    set_cookie(cookie_name, item.ID);
                }

                if (current_id && current_id == item.ID) {
                    bindCompletionItem(item);
                } else {
                    addInteractiveItem(item);
                }
                added = true;
            }


            // we _must_ go through all the interactives though because the item _may_ be
            // hitting an inbound request
            for (var i = 0; i < campaign.interactives.length; i++) {
                item = campaign.interactives[i];

                // we _dont_ re-add an item if it's the _current target_ of a given interactive.
                // however we _do_ check if it needs to be handled as a completion event
                if (current_id && current_id == item.ID) {
                    bindCompletionItem(item);
                    continue;
                }

                // we don't need to add if 'added' flagged based on random
                // determination above; however, the completion event _may_ need
                // to be added
                if (added) {
                    continue;
                }

                // if we're looking for a particular ID
                if (showId) {
                    if (item.ID == showId) {
                        return addInteractiveItem(item);
                    }
                } else {
                    addInteractiveItem(item);
                }
            }
        }
    }

    /**
     * Binds the completion interaction of a given item
     *
     * @param object item
     * @returns
     */
    function bindCompletionItem(item) {
        // bind a handler for the 'completion' element, but we don't display anything
        if (item.CompletionElement && !item.interactiveAlreadyBound) {
            document.documentElement.addEventListener('click', function (e) {
                if (e.target.matches(item.CompletionElement)) {
                    e.target.setAttribute('data-int-type', 'cpl');
                    e.target.setAttribute('data-intid', item.ID);
                    return recordClick.call(e.target, e);
                }
            })
        }
        item.interactiveAlreadyBound = true;
    }

    /**
     * Adds a new interactive item into the page
     *
     * Takes into account the location to be added, and any
     * handlers that need binding on contained 'a' elements.
     *
     * @param {type} item
     * @returns {undefined}
     */
    function addInteractiveItem(item) {
        var target;
        var addFunction = '';
        var holder = [];

        var effect = 'show';

        var hidden = get_cookie('interacted');
        if (hidden && hidden.length) {
            hidden = hidden.split('|');
            if (hidden.indexOf("" + item.ID) >= 0 && item.HideAfterInteraction != 0) {
                return;
            }
        }

        if (item.Frequency > 0) {
            var rand = Math.floor(Math.random() * (item.Frequency)) + 1;
            if (rand != 1) {
                // k good to go
                return;
            }
        }

        if (item.Element) {
            // we can only re-add items that have a specific 'element' being targeted,
            // this way we can skip them later on if we find the element again
            add_later['item-' + item.ID] = item;

            target = [].slice.call(document.querySelectorAll(item.Element));
            if (target) {
                target = target.filter(function (elem) {
                    return !elem.classList.contains('ss-int-tgt');
                });
            }

            if (!target.length) {
                return;
            }
            target.forEach(function (elem) {
                elem.classList.add('ss-int-tgt');
            });
        }

        if (item.Location != 'existing') {
            var canUse = allowed_add_actions.indexOf(item.Location);
            addFunction = canUse >= 0 ? item.Location : '';
        }

        if (item.Transition && item.Transition != 'show') {
            effect = item.Transition;
        }

        if (addFunction.length) {
            var holderElem = document.createElement('div');
            holderElem.classList.add('ss-interactive-item');
            holderElem.style.display = 'none';
            holderElem.innerHTML = item.Content;
            holder = [holderElem];
        } else {
            holder = target;
            holder.forEach(function (item) {
                item.classList.add('ss-interactive-item');
            })
        }

        holder.forEach(function (elem) {
            elem.querySelectorAll('a,button').forEach(function (innerElem) {
                innerElem.setAttribute('data-intid', item.ID);

                // see whether we have a specific target link to replace this with
                if (item.TargetLink && item.TargetLink.length > 0) {
                    innerElem.setAttribute('href', item.TargetLink);
                }

                innerElem.classList.add('int-link');

                // if there's a completion element identified, we pass on the information about
                // this item in the link
                if (item.CompletionElement) {
                    var append = 'int_src=' + current_uuid() + '&int_id=' + item.ID;
                    var newLink = innerElem.getAttribute('href');
                    if (newLink.indexOf('?') >= 0) {
                        append = "&" + append;
                    } else {
                        append = "?" + append;
                    }
                    innerElem.setAttribute('href', newLink + append);
                }

                if (item.HideAfterInteraction) {
                    innerElem.classList.add('hide-on-interact');
                }
            });

            elem.setAttribute('data-intid', item.ID)
            if (item.TrackViews) {
                elem.classList.add('int-track-view');
            }
        });

        var timeout = item.Delay ? item.Delay : 0;

        if (addFunction.length) {
            setTimeout(function () {
                // Add the item using the appropriate location
                // target[addFunction](holder);

                target.forEach(function (elem) {
                    var position = legacy_add_mapping[addFunction];
                    holder.forEach(function (insertElem) {
                        if (position === 'html') {
                            elem.innerHTML = '';
                            elem.appendChild(insertElem);
                        } else {
                            elem.insertAdjacentElement(position, insertElem);
                        }

                        insertElem.style.display = '';
                    })
                });

                triggerEvent(document, 'ss_interactive_loaded', item);
            }, timeout);
        }
    };

    /**
     * Checks display rules for whether this interactive should
     * display or not
     * 
     * @param {object} campaign 
     */
    function canShow(campaign) {
        var can = campaign.siteWide == 1;

        // check includes and excludes
        if (can) {
            if (thisPageMatches(campaign.exclude)) {
                can = false;
            }
        } else {
            if (thisPageMatches(campaign.include)) {
                can = true;
            }
        }

        return can;
    }

    function thisPageMatches(rules) {
        for (var i = 0; i < rules.css.length; i++) {
            if (document.querySelector(rules.css[i])) {
                return true;
            }
        }
        for (var i = 0; i < rules.urls.length; i++) {
            var r = new RegExp(rules.urls[i]);
            
            if (r.exec(location.href)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Looks up the campaign fir a given interactive item
     *
     * NOTE: This lookup could be a lot quicker, as it's scanning
     * all items each call.
     *
     * @param {type} interactiveId
     * @returns {.campaign@arr;interactives.interactives}
     */
    function findCampaignFor(interactiveId) {
        if (config.campaigns.length) {
            for (var i = 0; i < config.campaigns.length; i++) {
                var campaign = config.campaigns[i];
                if (campaign.interactives.length) {
                    for (var j = 0; j < campaign.interactives.length; j++) {
                        if (campaign.interactives[j].ID == interactiveId) {
                            return campaign;
                        }
                    }
                }
            }
        }
    };

    function current_uuid() {
        // check the URL string for a continual UUID
        if (uuid) {
            return uuid;
        }

        var uid = null;

        if (config.remember) {
            // check in a cookie
            uid = get_cookie('uuid');
        }

        // anything explicitly set in the config is used
        if (SSInteractives.uuid) {
            uid = SSInteractives.uuid;
        }

        // check the URL string
        if (!uid) {
            uid = url_uuid();
        }

        if (!uid) {
            uid = UUID().generate();
            if (config.remember) {
                set_cookie('uuid', uid);
            }
        }

        uuid = uid;
        return uid;
    }

    function url_uuid() {
        return get_url_param('int_src');
    }



    Trackers.Google = {
        track: function (ids, event, uid) {
            var category = 'Interactives';

            var uid = uid ? uid : current_uuid();
            var action = event;

            var allIds = ids.split(',');

            for (var i = 0; i < allIds.length; i++) {
                var label = 'id:' + allIds[i] + '|uid:' + uid;
                if (window._gaq) {
                    window._gaq.push(['_trackEvent', category, action, label]);
                } else if (window.ga) {
                    ga('send', {
                        hitType: 'event',
                        eventCategory: category,
                        eventAction: action,
                        eventLabel: label
                    });
                }
            }
        }
    };

    Trackers.Local = {
        track: function (ids, event, uid) {
            var uid = current_uuid();
            var xhr = new XMLHttpRequest();

            xhr.onload = function () {
                if (xhr.status === 200) {
                }
                else if (xhr.status !== 200) {
                    console.error("Failed posting interactive data", xrh.status);
                }
            };
            var data = [];
            data.push("ids=" + encodeURIComponent(ids));
            data.push("evt=" + encodeURIComponent(event));
            data.push("sig=" + encodeURIComponent(uid));
            data.push("itm=" + encodeURIComponent(config.item));

            xhr.open('POST', config.endpoint);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send(data.join('&'));

            // $.post(config.endpoint, { ids: ids, evt: event, sig: uid, itm: config.item });
        }
    };


    /**
     * Fast UUID generator, RFC4122 version 4 compliant.
     * @author Jeff Ward (jcward.com).
     * @license MIT license
     * @link http://jcward.com/UUID.js
     **/
    function UUID() {
        var self = {};
        var lut = [];
        for (var i = 0; i < 256; i++) {
            lut[i] = (i < 16 ? '0' : '') + (i).toString(16);
        }
        self.generate = function () {
            var d0 = Math.random() * 0xffffffff | 0;
            var d1 = Math.random() * 0xffffffff | 0;
            var d2 = Math.random() * 0xffffffff | 0;
            var d3 = Math.random() * 0xffffffff | 0;
            return lut[d0 & 0xff] + lut[d0 >> 8 & 0xff] + lut[d0 >> 16 & 0xff] + lut[d0 >> 24 & 0xff] + '-' +
                lut[d1 & 0xff] + lut[d1 >> 8 & 0xff] + '-' + lut[d1 >> 16 & 0x0f | 0x40] + lut[d1 >> 24 & 0xff] + '-' +
                lut[d2 & 0x3f | 0x80] + lut[d2 >> 8 & 0xff] + '-' + lut[d2 >> 16 & 0xff] + lut[d2 >> 24 & 0xff] +
                lut[d3 & 0xff] + lut[d3 >> 8 & 0xff] + lut[d3 >> 16 & 0xff] + lut[d3 >> 24 & 0xff];
        }
        return self;
    };


    //<editor-fold defaultstate="collapsed" desc="Cookie management">
    function set_cookie(name, value, days) {
        var expires = "";
        if (!days) {
            days = 30;
        }
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        name = config.cookieprefix + name;
        document.cookie = name + "=" + value + expires + "; path=/";
    }

    function get_cookie(name) {
        name = config.cookieprefix + name;
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ')
                c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) == 0)
                return c.substring(nameEQ.length, c.length);
        }
        return null;
    }

    function clear_cookie(name) {
        set_cookie(name, "", -1);
    }
    //</editor-fold>

    function get_url_param(sParam) {
        var sPageURL = decodeURIComponent(window.location.search.substring(1)),
            sURLVariables = sPageURL.split('&'),
            sParameterName,
            i;

        for (i = 0; i < sURLVariables.length; i++) {
            sParameterName = sURLVariables[i].split('=');

            if (sParameterName[0] === sParam) {
                return sParameterName[1] === undefined ? true : sParameterName[1];
            }
        }
    };

    // allows for external initialisation
    window.init_ss_interactives = init_interactives;

    window.ss_interactive_lib = {
        cookie: {
            set: set_cookie,
            get: get_cookie,
            clear: clear_cookie
        },
        bindCompletionItem: bindCompletionItem,
        uuid: function () {
            return current_uuid();
        },
        interacted: function (id) {
            var interacted = get_cookie('interacted');
            if (interacted && interacted.length) {
                interacted = interacted.split('|');
                if (interacted.indexOf("" + id) >= 0) {
                    return true;
                }
            }
            return false;
        }
    }

    function triggerEvent(context, name, properties) {
        var param = properties ? { detail: properties } : null;
        var event = new CustomEvent(name, param);
        context.dispatchEvent(event);
    }

})();


/**
 * CustomEvent polyfill
 */
(function () {

    if (!Element.prototype.matches) {
        Element.prototype.matches = Element.prototype.msMatchesSelector ||
            Element.prototype.webkitMatchesSelector;
    }


    if (typeof window.CustomEvent === "function") return false;

    function CustomEvent(event, params) {
        params = params || { bubbles: false, cancelable: false, detail: undefined };
        var evt = document.createEvent('CustomEvent');
        evt.initCustomEvent(event, params.bubbles, params.cancelable, params.detail);
        return evt;
    }

    CustomEvent.prototype = window.Event.prototype;

    window.CustomEvent = CustomEvent;
})();
