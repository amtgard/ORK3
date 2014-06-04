using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Reflection;

namespace TestServices
{
    public partial class UnitTest
    {

        public string LoginAsKingdomPM()
        {
            AuthorizationService.AuthorizeRequest authreq = new AuthorizationService.AuthorizeRequest();
            authreq.UserName = "kpmone";
            authreq.Password = "p455w0rd";
            AuthorizationService.AuthorizeResponse authresp = authsvc.Authorize(authreq);

            if (authresp.Status.Status == 0)
            {
                return authresp.Token;
            }
            else
            {
                throw new Exception(authresp.Status.Detail);
            }
        }

        public string LoginAsAdmin()
        {
            AuthorizationService.AuthorizeRequest authreq = new AuthorizationService.AuthorizeRequest();
            authreq.UserName = "admin";
            authreq.Password = "p455w0rd";
            AuthorizationService.AuthorizeResponse authresp = authsvc.Authorize(authreq);

            if (authresp.Status.Status == 0)
            {
                return authresp.Token;
            }
            else
            {
                throw new Exception(authresp.Status.Detail);
            }
        }
    }

}