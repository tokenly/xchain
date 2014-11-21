do ($=jQuery)->

    client = new window.Faye.Client('http://faye.tokenly.dev:8200/public')
    txList = $('.transactionList')

    # client.addExtension {
    #     outgoing: (message, callback)->
    #         console.log "message.channel=",message.channel
    #         if message.channel == '/meta/connect'
    #             console.log "connected with #{message.connectionType}"
    #         return
    # }

    client.subscribe '/tick', (message)->
        console.log('ts=',message.ts);
        return

    client.subscribe '/tx', (event)->
        newEl = $("""
            <div class="row #{if event.isCounterpartyTx then 'xcp' else 'btc'}-tx">
                <span class="highlight"></span>

                <div class="medium-9 columns txid">
                    <a href="https://blockchain.info/tx/#{event.txid}">#{event.txid}</a>
                </div>
                <div class="medium-3 columns amount">
                    #{event.quantity} #{event.asset}
                </div>
            </div>   

        """)

        newEl.hide().prependTo(txList).slideDown()

        $('div.row', txList).slice(24).remove()
        return

    return

# 'txid'             => $tx_event['txid'],
# 'isCounterpartyTx' => $tx_event['isCounterpartyTx'],
# 'quantity'         => $tx_event['quantity'],
# 'asset'            => $tx_event['asset'],
# 'source'           => $tx_event['sources'][0],
# 'destination'      => $tx_event['destinations'][0],
# 'asset'            => $tx_event['asset'],
