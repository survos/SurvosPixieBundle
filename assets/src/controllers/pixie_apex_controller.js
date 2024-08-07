import { Controller } from '@hotwired/stimulus';
import ApexCharts from 'apexcharts'
/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://github.com/symfony/stimulus-bridge#lazy-controllers
*/
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['chart'];
    static values = {
        url: String,
        title: String,
        data: String
    }
    // ...


    connect() {
        super.connect();
        this.populate();
    }

    populate()
    {
        const data = JSON.parse(this.dataValue);
        let chartData = [];
        for (const row of data) {
            chartData.push({
                x: row.value + '',
                y: row.count
            })

        }
        console.log(chartData);
        // for (const [key, value] of Object.entries(this.data)) {
        //     console.log()
        // }
        var options = {
            series: [
                {
                    data: chartData
                }
            ],
            legend: {
                show: false
            },
            chart: {
                height: 450,
                type: 'treemap'
            },
            title: {
                text: this.titleValue
            }
        };

        var chart = new ApexCharts(this.chartTarget, options);
        chart.render();
    }
}
