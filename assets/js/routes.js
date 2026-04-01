/**
 * Shared route rendering for index.php and orders.php.
 * - Stops follow daily_orders.id order (fixed sequence, no Google waypoint optimization).
 * - Splits into multiple Directions requests when a driver has more than 24 stops per leg
 *   (max 23 intermediate waypoints per Google Directions request).
 */
(function (global) {
    'use strict';

    /** @type {number} Max intermediate waypoints per Directions request */
    var MAX_WAYPOINTS = 23;

    /**
     * @param {Array<Object>} driverOrders
     * @returns {Array<Object>}
     */
    function sortStopsByOrderId(driverOrders) {
        return driverOrders.slice().sort(function (a, b) {
            var idA = parseInt(a.id != null ? a.id : a.order_id, 10) || 0;
            var idB = parseInt(b.id != null ? b.id : b.order_id, 10) || 0;
            return idA - idB;
        });
    }

    /**
     * @param {Array<Object>} driverOrders
     * @returns {Array<{location: google.maps.LatLngLiteral, order: Object}>}
     */
    function buildOrderedLocations(driverOrders) {
        return sortStopsByOrderId(driverOrders)
            .map(function (o) {
                return {
                    location: {
                        lat: parseFloat(o.latitude),
                        lng: parseFloat(o.longitude)
                    },
                    order: o
                };
            })
            .filter(function (p) {
                return Number.isFinite(p.location.lat) && Number.isFinite(p.location.lng);
            });
    }

    /**
     * @param {google.maps.LatLngLiteral} factoryLocation
     * @param {Array<{location: google.maps.LatLngLiteral}>} locations
     * @returns {Array<google.maps.DirectionsRequest>}
     */
    function buildRouteSegments(factoryLocation, locations) {
        var segments = [];
        if (!locations.length) {
            return segments;
        }

        var origin = factoryLocation;
        var i = 0;
        var n = locations.length;
        var maxPerSegment = MAX_WAYPOINTS + 1;

        while (i < n) {
            var remaining = n - i;

            if (remaining === 1) {
                segments.push({
                    origin: origin,
                    destination: locations[i].location,
                    waypoints: [],
                    optimizeWaypoints: false,
                    travelMode: google.maps.TravelMode.DRIVING
                });
                break;
            }

            if (remaining <= maxPerSegment) {
                var slice = locations.slice(i);
                var dest = slice[slice.length - 1];
                var wps = slice.slice(0, slice.length - 1).map(function (p) {
                    return { location: p.location, stopover: true };
                });
                segments.push({
                    origin: origin,
                    destination: dest.location,
                    waypoints: wps,
                    optimizeWaypoints: false,
                    travelMode: google.maps.TravelMode.DRIVING
                });
                break;
            }

            var sliceEnd = i + maxPerSegment;
            var chunk = locations.slice(i, sliceEnd);
            var destChunk = chunk[chunk.length - 1];
            var wpsChunk = chunk.slice(0, chunk.length - 1).map(function (p) {
                return { location: p.location, stopover: true };
            });
            segments.push({
                origin: origin,
                destination: destChunk.location,
                waypoints: wpsChunk,
                optimizeWaypoints: false,
                travelMode: google.maps.TravelMode.DRIVING
            });
            origin = destChunk.location;
            i = sliceEnd;
        }

        return segments;
    }

    /**
     * @param {google.maps.DirectionsService} directionsService
     * @param {google.maps.Map} map
     * @param {google.maps.LatLngLiteral} factoryLocation
     * @param {Array<Object>} driverOrders
     * @param {string} color
     * @returns {Promise<Array<google.maps.DirectionsRenderer>>}
     */
    function renderDriverRoute(directionsService, map, factoryLocation, driverOrders, color) {
        var locations = buildOrderedLocations(driverOrders);
        if (!locations.length) {
            return Promise.resolve([]);
        }

        var segments = buildRouteSegments(factoryLocation, locations);
        var renderers = [];

        return segments.reduce(function (promise, seg) {
            return promise.then(function () {
                return new Promise(function (resolve) {
                    directionsService.route(seg, function (result, status) {
                        if (status === 'OK') {
                            var renderer = new google.maps.DirectionsRenderer({
                                map: map,
                                suppressMarkers: true,
                                polylineOptions: {
                                    strokeColor: color,
                                    strokeWeight: 3
                                }
                            });
                            renderer.setDirections(result);
                            renderers.push(renderer);
                        }
                        resolve();
                    });
                });
            });
        }, Promise.resolve()).then(function () {
            return renderers;
        });
    }

    /**
     * @param {Array<google.maps.DirectionsRenderer>} renderers
     */
    function clearRenderers(renderers) {
        if (!renderers) {
            return;
        }
        if (Array.isArray(renderers)) {
            renderers.forEach(function (r) {
                if (r) {
                    r.setMap(null);
                }
            });
        } else {
            renderers.setMap(null);
        }
    }

    global.RovanaRoutes = {
        MAX_WAYPOINTS: MAX_WAYPOINTS,
        sortStopsByOrderId: sortStopsByOrderId,
        buildOrderedLocations: buildOrderedLocations,
        buildRouteSegments: buildRouteSegments,
        renderDriverRoute: renderDriverRoute,
        clearRenderers: clearRenderers
    };
})(typeof window !== 'undefined' ? window : this);
