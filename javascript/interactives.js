
(function ($) {
    var config = {
        remember: false,         // remember the user through requests
        trackviews: false,
        trackclicks: true,      // should clicks be 
        trackforward: true,     // should target links be hooked and have a uid appended?
        items: [],
        tracker: 'Local',
        endpoint: '',
        cookieprefix: 'int_'
    };
    
    var uuid = null;
    
    var allowed_add_actions = ['prepend', 'append', 'before', 'after', 'html'];
    
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
            var idsArr = (""+ ids).split(',');
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
    
    $().ready(function () {
        if (!window.SSInteractives) {
            return;
        }
        
        for (var property in window.SSInteractives.config) {
            config[property] = window.SSInteractives.config[property];
        }

        if (!config.endpoint) {
            var base = $('base').attr('href');
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
        if (uid &&  current_id && config.trackforward) {
            tracker.track(current_id, 'int');
        }

        if (config.trackclicks) {
            $(document).on('click', 'a.int-link', recordClick);
        }

        processViews();
        
        setTimeout(reprocess, 5000);
    });
    
    function recordClick(e) {
        var target = $(this).prop("tagName").toLowerCase();
        var isLink = target === 'a';
        var navLink = $(this).attr('href');
        
        // is there a specific type of click
        var clickType = $(this).attr('data-int-type');
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

        var adId = $(this).attr('data-intid');
        if (e.which < 3) {
            tracker.track(adId, clickType);
        }

        if ($(this).hasClass('hide-on-interact')) {
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
                window.location.href = navLink;
            }, 200);
        }
        
        // or was it a form?
        if (target === 'input') {
            if ($(this).attr('type') === 'submit') {
                // submit the parent form
                e.preventDefault();
                
                var form = $(this).parents('form');
                $(this).val('Please wait...');
                
                setTimeout(function () {
                    form.submit();
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
        var ads = $('.int-track-view');
        var ids = [];
        for (var i = 0, c = ads.length; i < c; i++) {
            var adId = $(ads[i]).attr('data-intid');
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
        if (item.CompletionElement  && !item.interactiveAlreadyBound) {
            $(document).on('click', item.CompletionElement, function (e) {
                $(this).attr('data-int-type', 'cpl');
                $(this).attr('data-intid', item.ID);
                return recordClick.call(this, e);
            });
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
        var holder = null;
        
        var effect = 'show';
        
        var hidden = get_cookie('interacted');
        if (hidden && hidden.length) {
            hidden = hidden.split('|');
            if (hidden.indexOf("" + item.ID) >= 0 && item.HideAfterInteraction) {
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
            
            target = $(item.Element).filter(function () {
                return !$(this).hasClass('ss-int-tgt');
            });
            
            if (!target.length) {
                return;
            }
            target.each(function() {
                $(this).addClass('ss-int-tgt');
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
            holder = $('<div class="ss-interactive-item">').hide().append(item.Content);
        } else {
            holder = target;
            holder.addClass('ss-interactive-item');
        }
        
        holder.each(function () {
            $(this).find('a').each(function () {
                $(this).attr('data-intid', item.ID);

                // see whether we have a specific target link to replace this with
                if (item.TargetLink && item.TargetLink.length > 0) {
                    $(this).attr('href', item.TargetLink);
                }

                $(this).addClass('int-link'); 

                // if there's a completion element identified, we pass on the information about
                // this item in the link
                if (item.CompletionElement) {
                    var append = 'int_src=' + current_uuid() + '&int_id=' + item.ID;
                    var newLink = $(this).attr('href');
                    if (newLink.indexOf('?') >= 0) {
                        append = "&" + append;
                    } else {
                        append = "?" + append;
                    }
                    $(this).attr('href', newLink + append);
                }

                if (item.HideAfterInteraction) {
                    $(this).addClass('hide-on-interact');
                }
            });

            $(this).attr('data-intid', item.ID)
            if (item.TrackViews) {
                $(this).addClass('int-track-view');
            }
        });
        
        var timeout = item.Delay ? item.Delay : 0;

        if (addFunction.length) {
            setTimeout(function () {
                // Add the item using the appropriate location
                target[addFunction](holder);
                // and effect for showing
                holder[effect]();
            }, timeout);
        }
    };
    
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

        // check the URL string
        if (!uid) {
            uid = url_uuid();
        }

        if (!uid) {
            uid = UUID().generate();
            set_cookie('uuid', uid);
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
            $.post(config.endpoint, {ids: ids, evt: event, sig: uid});
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
    
    
})(jQuery);