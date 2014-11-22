(function() {
  window.Streamer = (function($) {
    var exports;
    exports = {};
    exports.init = function(pusherUrl) {
      var client, txList;
      client = new window.Faye.Client("" + pusherUrl + "/public");
      txList = $('.transactionList');
      client.addExtension({
        outgoing: function(message, callback) {
          console.log("message.channel=", message.channel);
          if (message.channel === '/meta/connect') {
            console.log("connected with " + message.connectionType);
          }
        }
      });
      client.subscribe('/tick', function(message) {
        console.log('ts=', message.ts);
      });
      client.subscribe('/tx', function(event) {
        var newEl;
        newEl = $("<div class=\"row " + (event.isCounterpartyTx ? 'xcp' : 'btc') + "-tx\">\n    <span class=\"highlight\"></span>\n\n    <div class=\"medium-9 columns txid\">\n        <a href=\"https://blockchain.info/tx/" + event.txid + "\">" + event.txid + "</a>\n    </div>\n    <div class=\"medium-3 columns amount\">\n        " + event.quantity + " " + event.asset + "\n    </div>\n</div>   \n");
        newEl.hide().prependTo(txList).slideDown();
        $('div.row', txList).slice(24).remove();
      });
    };
    return exports;
  })(jQuery);

}).call(this);
