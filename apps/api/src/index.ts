import {Hono} from 'hono'
import {cors} from 'hono/cors'
import type {Env} from './env'
import {requireAuth} from './middleware/auth'
import users from './routes/users'

const app=new Hono<{Bindings:Env}>()
app.use('*',async(c,next)=>cors({origin:c.env.ALLOWED_ORIGIN.split(',').map(x=>x.trim()),allowHeaders:['Authorization','Content-Type'],allowMethods:['GET','POST','PATCH','DELETE','OPTIONS'],maxAge:86400})(c,next))
app.get('/health',c=>c.json({ok:true,service:'hybrid-shop-api'}))
app.use('/v1/*',requireAuth)
app.route('/v1/users',users)
app.notFound(c=>c.json({error:'not_found'},404))
app.onError((err,c)=>{console.error(err);return c.json({error:'internal_error'},500)})
export default app
