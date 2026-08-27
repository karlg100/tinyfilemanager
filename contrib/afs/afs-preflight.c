/* SPDX-License-Identifier: MIT */
#include <kopenafs.h>
#include <afs/param.h>
#include <afs/auth.h>

int main(void)
{
    if (!k_hasafs())
        return 1;
    if (k_setpag() != 0 || !k_haspag())
        return 1;
    if (ktc_ForgetAllTokens() != 0)
        return 1;
    return 0;
}
