const { Client } = require('pg');

async function testRealtimeNotifications() {
    const client = new Client({
        host: '172.31.1.10',
        port: 5432,
        database: 'db_drubase',
        user: 'postgres',
        password: ''
    });

    try {
        await client.connect();
        console.log('🎯 PostgreSQL 实时通知监听器');
        console.log('==================================');
        console.log('📡 正在监听频道: realtime_changes');
        console.log('💡 请在另一个终端执行数据库操作...');
        console.log('🛑 按 Ctrl+C 退出');
        console.log('==================================');

        // 监听通知
        await client.query('LISTEN realtime_changes');

        client.on('notification', (msg) => {
            const timestamp = new Date().toLocaleTimeString();
            console.log(`\n🔔 [${timestamp}] 收到实时通知!`);
            console.log(`   📺 频道: ${msg.channel}`);
            
            try {
                const payload = JSON.parse(msg.payload);
                console.log(`   📊 数据:`, JSON.stringify(payload, null, 2));
                
                const table = payload.table || 'N/A';
                const operation = payload.type || 'N/A';
                const dbTimestamp = payload.timestamp || 'N/A';
                
                console.log(`   🏷️  表名: ${table}`);
                console.log(`   ⚡ 操作: ${operation}`);
                console.log(`   🕐 时戳: ${dbTimestamp}`);
                
            } catch (e) {
                console.log(`   🔴 原始数据: ${msg.payload}`);
            }
            
            console.log('-'.repeat(50));
        });

        // 保持连接活跃  
        setInterval(() => {
            client.query('SELECT 1').catch(console.error);
        }, 30000);

        // 等待用户中断
        process.on('SIGINT', async () => {
            console.log('\n\n👋 监听已停止');
            await client.end();
            process.exit(0);
        });

        // 无限等待
        await new Promise(() => {});

    } catch (error) {
        console.error('❌ 错误:', error);
        await client.end();
    }
}

testRealtimeNotifications();